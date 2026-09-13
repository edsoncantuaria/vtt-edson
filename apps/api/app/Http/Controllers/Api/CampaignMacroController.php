<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignMacro;
use App\Models\CampaignResourcePermission;
use App\Models\Scene;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\MacroExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CampaignMacroController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        $manage = $campaign->canManage($request->user()) || $campaign->can($request->user(), 'macros.manage');
        $granted = CampaignResourcePermission::query()->where('campaign_id', $campaign->id)
            ->where('user_id', $request->user()->id)->where('resource_type', 'macro')->pluck('resource_id');
        $macros = CampaignMacro::where('campaign_id', $campaign->id)->where('enabled', true)
            ->when(! $manage, fn ($query) => $query->where(function ($visible) use ($request, $granted) {
                $visible->where('visibility', 'campaign')->orWhere('owner_user_id', $request->user()->id)->orWhereIn('id', $granted);
            }))->orderByRaw('hotbar_slot is null')->orderBy('hotbar_slot')->orderBy('name')->get();

        return response()->json(['macros' => $macros]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        abort_if($campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        $data = $this->payload($request);
        if (($data['visibility'] ?? 'owner') !== 'owner') {
            abort_unless($campaign->canManage($request->user()) || $campaign->can($request->user(), 'macros.manage'), 403);
        }
        $macro = CampaignMacro::create(['campaign_id' => $campaign->id, 'owner_user_id' => $request->user()->id, ...$data]);

        return response()->json(['macro' => $macro], 201);
    }

    public function update(Request $request, CampaignMacro $campaignMacro): JsonResponse
    {
        $this->requireEdit($request, $campaignMacro);
        $campaignMacro->update($this->payload($request, true));

        return response()->json(['macro' => $campaignMacro->fresh()]);
    }

    public function destroy(Request $request, CampaignMacro $campaignMacro): JsonResponse
    {
        $this->requireEdit($request, $campaignMacro);
        $campaignMacro->delete();

        return response()->json(['ok' => true]);
    }

    public function execute(Request $request, CampaignMacro $campaignMacro, MacroExecutor $executor): JsonResponse
    {
        abort_unless($campaignMacro->enabled && $campaignMacro->campaign->isMember($request->user()), 403);
        abort_if($campaignMacro->campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        $canUse = $campaignMacro->campaign->canManage($request->user())
            || (int) $campaignMacro->owner_user_id === (int) $request->user()->id
            || $campaignMacro->visibility === 'campaign'
            || CampaignResourcePermission::permits($campaignMacro->campaign, $request->user(), 'macro', $campaignMacro->id, 'view');
        abort_unless($canUse, 403);
        $data = $request->validate([
            'sceneId' => ['required', 'integer', 'exists:scenes,id'], 'actorId' => ['nullable', 'integer', 'exists:actors,id'],
            'targetActorIds' => ['sometimes', 'array', 'max:50'], 'targetActorIds.*' => ['integer', 'distinct'],
            'point' => ['nullable', 'array:x,y'], 'point.x' => ['required_with:point', 'numeric'], 'point.y' => ['required_with:point', 'numeric'],
        ]);
        $scene = Scene::findOrFail($data['sceneId']);
        abort_unless((int) $scene->campaign_id === (int) $campaignMacro->campaign_id, 422, 'A cena e a macro precisam pertencer à mesma campanha.');
        $result = $executor->execute($campaignMacro, $scene, $request->user(), $data['actorId'] ?? null, $data['targetActorIds'] ?? [], $data['point'] ?? null);

        return response()->json($result);
    }

    private function payload(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:120'], 'icon' => ['nullable', 'string', 'max:80'],
            'visibility' => ['sometimes', Rule::in(['owner', 'campaign', 'gm'])], 'hotbar_slot' => ['nullable', 'integer', 'between:1,10'],
            'enabled' => ['sometimes', 'boolean'], 'commands' => [$required, 'array', 'min:1', 'max:20'],
            'commands.*.type' => ['required', Rule::in(['roll', 'chat', 'effect', 'resource', 'ping'])],
            'commands.*.formula' => ['sometimes', 'string', 'max:80'],
            'commands.*.label' => ['sometimes', 'string', 'max:80'],
            'commands.*.text' => ['sometimes', 'string', 'max:1000'],
            'commands.*.target' => ['sometimes', Rule::in(['self', 'targets'])],
            'commands.*.name' => ['sometimes', 'string', 'max:160'],
            'commands.*.duration' => ['sometimes', 'array:unit,remaining'],
            'commands.*.duration.unit' => ['required_with:commands.*.duration', Rule::in(['rounds', 'minutes', 'hours', 'until-short-rest', 'until-long-rest', 'permanent'])],
            'commands.*.duration.remaining' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'commands.*.modifiers' => ['sometimes', 'array', 'max:30'],
            'commands.*.modifiers.*.path' => ['required', Rule::in(ActiveEffectEngine::modifierPaths())],
            'commands.*.modifiers.*.mode' => ['required', Rule::in(['add', 'multiply', 'override'])],
            'commands.*.modifiers.*.value' => ['required'],
            'commands.*.conditions' => ['sometimes', 'array', 'max:20'],
            'commands.*.conditions.*' => ['string', 'max:120'],
            'commands.*.resourceId' => ['sometimes', 'string', 'max:80'],
            'commands.*.usedDelta' => ['sometimes', 'integer', 'between:-1000,1000'],
        ]);
        foreach ($data['commands'] ?? [] as $command) {
            $type = $command['type'];
            if ($type === 'roll') {
                abort_unless(is_string($command['formula'] ?? null) && preg_match('/^[0-9dDkKhHlL+\-\s]+$/', $command['formula']), 422, 'Fórmula inválida na macro.');
            } elseif ($type === 'effect') {
                abort_unless(in_array($command['target'] ?? 'self', ['self', 'targets'], true), 422);
                abort_unless(is_array($command['modifiers'] ?? []) && is_array($command['conditions'] ?? []), 422);
                foreach ($command['modifiers'] ?? [] as $modifier) {
                    abort_unless(is_array($modifier)
                        && in_array($modifier['path'] ?? null, ActiveEffectEngine::modifierPaths(), true)
                        && in_array($modifier['mode'] ?? null, ['add', 'multiply', 'override'], true)
                        && (is_numeric($modifier['value'] ?? null) || (is_string($modifier['value'] ?? null) && preg_match('/^\d*d\d+(?:[+-]\d+)?$/i', $modifier['value']))), 422, 'Modificador inválido na macro.');
                }
            } elseif ($type === 'resource') {
                abort_unless(is_string($command['resourceId'] ?? null), 422, 'Informe o recurso da macro.');
            }
        }

        return $data;
    }

    private function requireEdit(Request $request, CampaignMacro $macro): void
    {
        abort_if($macro->campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        abort_unless($macro->campaign->canManage($request->user()) || (int) $macro->owner_user_id === (int) $request->user()->id
            || CampaignResourcePermission::permits($macro->campaign, $request->user(), 'macro', $macro->id, 'edit'), 403);
    }
}
