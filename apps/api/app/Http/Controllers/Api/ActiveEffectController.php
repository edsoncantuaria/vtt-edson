<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\ActiveEffectEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ActiveEffectController extends Controller
{
    public function index(Request $request, Actor $actor): JsonResponse
    {
        $this->requireView($request, $actor);

        return response()->json(['effects' => $actor->activeEffects()->get()]);
    }

    public function store(Request $request, Actor $actor): JsonResponse
    {
        $this->requireEdit($request, $actor);
        $effect = ActiveEffect::create(['actor_id' => $actor->id, ...$this->payload($request)]);

        return response()->json(['effect' => $effect, 'actor' => $actor->fresh()->toPayload()], 201);
    }

    public function update(Request $request, ActiveEffect $activeEffect): JsonResponse
    {
        $this->requireEdit($request, $activeEffect->actor);
        $activeEffect->update($this->payload($request, true));

        return response()->json(['effect' => $activeEffect->fresh(), 'actor' => $activeEffect->actor->fresh()->toPayload()]);
    }

    public function destroy(Request $request, ActiveEffect $activeEffect): JsonResponse
    {
        $actor = $activeEffect->actor;
        $this->requireEdit($request, $actor);
        $activeEffect->delete();

        return response()->json(['actor' => $actor->fresh()->toPayload()]);
    }

    private function payload(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';
        $paths = ActiveEffectEngine::modifierPaths();

        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'source_document_id' => ['nullable', 'integer', 'exists:actor_documents,id'],
            'duration' => [$required, 'array:unit,remaining'],
            'duration.unit' => [$required, Rule::in(['rounds', 'minutes', 'hours', 'until-short-rest', 'until-long-rest', 'permanent'])],
            'duration.remaining' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'modifiers' => [$required, 'array', 'max:30'],
            'modifiers.*.path' => ['required', Rule::in($paths)],
            'modifiers.*.mode' => ['required', Rule::in(['add', 'multiply', 'override'])],
            'modifiers.*.value' => ['required'],
            'conditions' => ['sometimes', 'array', 'max:20'],
            'conditions.*' => ['string', 'max:120'],
            'metadata' => ['sometimes', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);
        foreach ($data['modifiers'] ?? [] as $modifier) {
            abort_unless(is_array($modifier) && ActiveEffectEngine::validModifier($modifier), 422, 'Modificador de efeito inválido.');
        }

        return $data;
    }

    private function requireView(Request $request, Actor $actor): void
    {
        abort_unless($actor->campaign->isMember($request->user()), 403);
        abort_unless($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user()) || $actor->shared
            || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id), 403);
    }

    private function requireEdit(Request $request, Actor $actor): void
    {
        abort_unless($actor->campaign->roleFor($request->user()) !== 'observer'
            && ($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user())
                || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id, 'edit')), 403);
    }
}
