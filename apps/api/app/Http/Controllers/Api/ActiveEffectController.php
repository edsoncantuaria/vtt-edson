<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\EffectAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ActiveEffectController extends Controller
{
    public function index(Request $request, Actor $actor): JsonResponse
    {
        $this->requireView($request, $actor);

        $manager = $actor->campaign->canManage($request->user());

        return response()->json(['effects' => $actor->activeEffects()->get()
            ->filter(fn ($effect) => $manager || $effect->visibility !== 'gm')
            ->map(fn ($effect) => $manager ? $effect->toArray() : Arr::except($effect->toArray(),
                ['metadata', 'concentration_actor_id', 'concentration_id', 'source_document_id']))->values()]);
    }

    public function history(Request $request, Actor $actor): JsonResponse
    {
        abort_unless($actor->campaign->canManage($request->user()), 403);
        $events = DB::table('active_effect_events as event')
            ->leftJoin('users as author', 'event.user_id', '=', 'author.id')
            ->where('event.actor_id', $actor->id)->orderByDesc('event.id')->limit(100)
            ->get(['event.*', 'author.name as user_name']);

        return response()->json(['events' => $events]);
    }

    public function store(Request $request, Actor $actor): JsonResponse
    {
        $this->requireEdit($request, $actor);
        $data = $this->payload($request, $actor);

        return DB::transaction(function () use ($actor, $request, $data) {
            $effect = ActiveEffect::create(['actor_id' => $actor->id, 'active' => true, ...$data,
                'metadata' => ['source' => 'sheet', 'createdBy' => $request->user()->id]]);
            app(ActiveEffectEngine::class)->breakIfIncapacitating($effect);
            EffectAudit::record($effect, 'created', null, $request->user()->id);

            return response()->json(['effect' => $effect, 'actor' => $actor->fresh()->toPayload()], 201);
        });
    }

    public function update(Request $request, ActiveEffect $activeEffect): JsonResponse
    {
        $actor = $activeEffect->actor;
        $this->requireEdit($request, $actor);
        abort_if($activeEffect->visibility === 'gm' && ! $actor->campaign->canManage($request->user()), 403);
        $data = $this->payload($request, $actor, true);

        return DB::transaction(function () use ($request, $activeEffect, $data) {
            $before = $activeEffect->toArray();
            $activeEffect->update($data);
            app(ActiveEffectEngine::class)->breakIfIncapacitating($activeEffect);
            EffectAudit::record($activeEffect, 'updated', $before, $request->user()->id, $request->input('reason'));

            return response()->json(['effect' => $activeEffect->fresh(), 'actor' => $activeEffect->actor->fresh()->toPayload()]);
        });
    }

    public function destroy(Request $request, ActiveEffect $activeEffect): JsonResponse
    {
        $actor = $activeEffect->actor;
        $this->requireEdit($request, $actor);
        abort_if($activeEffect->visibility === 'gm' && ! $actor->campaign->canManage($request->user()), 403);

        return DB::transaction(function () use ($actor, $activeEffect, $request) {
            $before = $activeEffect->toArray();
            EffectAudit::record($activeEffect, 'removed', $before, $request->user()->id);
            $activeEffect->delete();

            return response()->json(['actor' => $actor->fresh()->toPayload()]);
        });
    }

    private function payload(Request $request, Actor $actor, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';
        $paths = ActiveEffectEngine::modifierPaths();

        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'source_document_id' => ['nullable', 'integer', 'exists:actor_documents,id'],
            'source_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'icon_url' => ['sometimes', 'nullable', 'url', 'max:2048', 'starts_with:https://'],
            'visibility' => ['sometimes', Rule::in(['public', 'gm'])],
            'duration' => [$required, 'array:unit,remaining,phase'],
            'duration.unit' => [$required, Rule::in(['rounds', 'minutes', 'hours', 'until-short-rest', 'until-long-rest', 'permanent'])],
            'duration.remaining' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'duration.phase' => ['sometimes', 'in:round,start,end'],
            'modifiers' => [$update ? 'sometimes' : 'present', 'array', 'max:30'],
            'modifiers.*.path' => ['required', Rule::in($paths)],
            'modifiers.*.mode' => ['required', Rule::in(['add', 'multiply', 'override'])],
            'modifiers.*.value' => ['required'],
            'conditions' => ['sometimes', 'array', 'max:20'],
            'conditions.*' => ['string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'string', 'max:240'],
        ]);
        unset($data['reason']);
        abort_if(($data['visibility'] ?? 'public') === 'gm' && ! $actor->campaign->canManage($request->user()), 403);
        if (isset($data['source_document_id'])) {
            abort_unless($actor->documents()->whereKey($data['source_document_id'])->exists(), 422, 'Documento de origem não pertence a esta ficha.');
        }
        if (isset($data['duration'])) {
            $duration = $data['duration'];
            abort_if(($duration['phase'] ?? 'round') !== 'round' && ($duration['unit'] ?? null) !== 'rounds', 422, 'Fase do turno exige duração em rodadas.');
            abort_if(in_array($duration['unit'] ?? null, ['rounds', 'minutes', 'hours'], true) && ! isset($duration['remaining']), 422, 'Informe a duração restante.');
        }
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
