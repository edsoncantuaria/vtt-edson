<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\ActorDocumentService;
use App\Support\Dnd\ResourceAudit;
use App\Support\Dnd\ResourcePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActorRestController extends Controller
{
    public function __construct(
        private readonly ActiveEffectEngine $effects,
        private readonly ActorDocumentService $documents,
    ) {}

    public function store(Request $request, Actor $actor): JsonResponse
    {
        return DB::transaction(function () use ($request, $actor) {
            $actor = Actor::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless(in_array($actor->campaign->roleFor($request->user()), ['gm', 'assistant', 'player'], true)
                && ($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user())
                    || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id, 'edit')), 403);
            $data = $request->validate(['rest' => ['required', 'in:short,long'], 'requestId' => ['sometimes', 'uuid']]);
            $rest = $data['rest'];
            $requestId = $data['requestId'] ?? (string) Str::uuid();
            $hash = hash('sha256', $rest);
            $previous = DB::table('actor_resource_events')->where(['actor_id' => $actor->id, 'request_id' => $requestId])->first();
            if ($previous) {
                abort_unless($previous->event === 'rest' && hash_equals((string) $previous->request_hash, $hash), 409,
                    'Chave de recuperação já usada em outra operação.');

                return response()->json(['actor' => $actor->toPayload(), 'replayed' => true]);
            }
            $beforePools = ResourceAudit::snapshot($actor);
            $system = $actor->system;
            $system = ResourcePool::rest($system, $rest, $actor->campaign->ruleset);
            if ($rest === 'long') {
                $system['hp']['value'] = $system['hp']['max'];
                $system['hp']['temp'] = 0;
                $system['deathSaves'] = ['success' => 0, 'failure' => 0];
                $hitDice = $system['hitDice'] ?? ['total' => 1, 'used' => 0];
                $recovered = $actor->campaign->ruleset === '5e-2024'
                    ? (int) ($hitDice['used'] ?? 0)
                    : min((int) ($hitDice['used'] ?? 0), max(1, (int) floor(($hitDice['total'] ?? 1) / 2)));
                $system['hitDice']['used'] = max(0, (int) ($hitDice['used'] ?? 0) - $recovered);
            }
            $actor->system = $system;
            $actor->save();
            $this->effects->clearForRest($actor, $rest);
            $this->documents->resetCharges($actor, $rest);
            ResourceAudit::record($actor, 'rest', $beforePools, ResourceAudit::snapshot($actor), $request->user()->id,
                $requestId, $hash, null, $rest.'-rest');

            return response()->json(['actor' => $actor->fresh()->toPayload()]);
        });
    }
}
