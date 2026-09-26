<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\RollLedger;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteSceneActionRequest;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\Scene;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\CombatRules;
use App\Support\Dnd\ConditionRules;
use App\Support\Dnd\EffectAudit;
use App\Support\Dnd\HouseRules;
use App\Support\Dnd\ResourceAudit;
use App\Support\Dnd\ResourcePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SceneActionController extends Controller
{
    use AuthorizesScene;

    public function action(ExecuteSceneActionRequest $request, Scene $scene, RollLedger $ledger, ActiveEffectEngine $effects): JsonResponse
    {
        // Roll + costs + effects + chat must succeed as a unit. The outer HTTP
        // exception handler may turn an abort into a response before middleware
        // exits; keep this transaction inside the controller as well.
        return DB::transaction(fn () => $this->execute($request, $scene, $ledger, $effects));
    }

    private function execute(ExecuteSceneActionRequest $request, Scene $scene, RollLedger $ledger, ActiveEffectEngine $effects): JsonResponse
    {
        $member = $this->requireParticipant($request, $scene);
        $data = $request->validated();
        $actor = Actor::query()->lockForUpdate()->findOrFail($data['actorId']);
        if ((int) $actor->campaign_id !== (int) $scene->campaign_id) {
            abort(422, 'Essa ficha não pertence à campanha desta cena.');
        }
        if (! $member->isGm() && ! $actor->isOwnedBy($request->user())) {
            abort(403, 'Você só pode usar ações da sua própria ficha.');
        }
        $requestId = $data['requestId'] ?? (string) Str::uuid();
        $targetActorIds = array_values(array_unique(array_map('intval', $data['targetActorIds'] ?? [])));
        sort($targetActorIds);
        $targetTokenIds = array_values(array_unique($data['targetTokenIds'] ?? []));
        sort($targetTokenIds);
        abort_if($targetTokenIds && $targetActorIds, 422, 'Escolha os alvos por token ou por ficha, não ambos.');
        // Keep the four-field legacy hash for retries created before token targeting.
        $hashInput = [$actor->id, $data['actionId'], $data['mode'] ?? 'normal', $targetActorIds];
        if ($targetTokenIds) {
            $hashInput[] = ['targetTokenIds' => $targetTokenIds];
        }
        $inputHash = hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR));
        $existing = DB::table('action_records')->where(['scene_id' => $scene->id, 'user_id' => $request->user()->id, 'request_id' => $requestId])->first();
        if ($existing) {
            abort_unless((int) $existing->actor_id === $actor->id, 409);
            abort_if($existing->input_hash !== null && ! hash_equals($existing->input_hash, $inputHash), 409, 'Esta chave já foi usada em outra ação ou seleção de alvos.');
            $previousMessage = json_decode($existing->message, true, flags: JSON_THROW_ON_ERROR);
            abort_if(($previousMessage['visibility'] ?? 'public') === 'gm' && ! $scene->campaign->canManage($request->user()), 403, 'Ação reservada ao mestre.');

            return response()->json(['message' => $scene->messageFor($request->user(), $previousMessage), 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user())]);
        }
        $action = collect($actor->system['actions'] ?? [])->firstWhere('id', $data['actionId']);
        if (! $action) {
            abort(404, 'Ação não encontrada na ficha.');
        }
        abort_if(($action['visibility'] ?? 'public') === 'gm' && ! $scene->campaign->canManage($request->user()), 403, 'Ação reservada ao mestre.');
        CombatRules::validateAction($action);
        $actionSnapshot = $action;
        $actionRevision = (int) $actor->revision;
        $targetTokensByActor = [];
        if ($targetTokenIds) {
            // Resolve public token handles inside the locked scene. The caller never
            // needs the private actor IDs backing visible enemy tokens.
            $visibleTokenIds = array_column($scene->visibleTokensFor($request->user()), 'id');
            $canonicalTokens = collect($scene->state['tokens'] ?? [])->keyBy('id');
            foreach ($targetTokenIds as $tokenId) {
                abort_unless(in_array($tokenId, $visibleTokenIds, true), 403, 'O token não está visível nesta cena.');
                $token = $canonicalTokens->get($tokenId);
                abort_unless($token && is_int($token['actorId'] ?? null), 422, 'O token selecionado não possui ficha vinculada.');
                $targetTokensByActor[(int) $token['actorId']][] = $token;
            }
            abort_unless(count($targetTokensByActor) === count($targetTokenIds), 422, 'Selecione apenas uma instância por ficha vinculada.');
            $targetActorIds = array_map('intval', array_keys($targetTokensByActor));
            sort($targetActorIds);
            abort_unless($scene->campaign->actors()->whereIn('id', $targetActorIds)->count() === count($targetActorIds), 422, 'Um dos alvos não pertence à campanha.');
        }
        $targetMode = $action['target'] ?? null;
        if ($targetMode === 'self') {
            abort_unless(! $targetActorIds || $targetActorIds === [$actor->id], 422, 'Esta ação só pode mirar a própria ficha.');
            $targetActorIds = [$actor->id];
        } elseif ($targetMode === 'single') {
            abort_unless(count($targetActorIds) === 1, 422, 'Selecione exatamente um alvo.');
        } elseif ($targetMode === 'multiple') {
            abort_unless(count($targetActorIds) >= 1 && count($targetActorIds) <= (int) ($action['maxTargets'] ?? 50), 422, 'Revise a quantidade de alvos desta ação.');
        }
        if ($targetActorIds && ! $member->isGm() && ! $targetTokenIds) {
            $visibleIds = collect($scene->stateFor($request->user())['tokens'] ?? [])
                ->pluck('actorId')->filter()->map(fn ($id) => (int) $id)->all();
            $visibleIds[] = $actor->id;
            abort_unless(! array_diff($targetActorIds, $visibleIds), 403, 'O alvo não está disponível na sua visão da cena.');
        }
        if (isset($action['rangeFeet']) && $targetActorIds && $targetMode !== 'self') {
            $tokens = collect($scene->state['tokens'] ?? []);
            $source = $tokens->first(fn ($token) => (int) ($token['actorId'] ?? 0) === $actor->id);
            abort_unless($source, 422, 'Coloque a ficha no mapa para medir o alcance.');
            $grid = max(1, (float) ($scene->state['grid']['size'] ?? 70));
            foreach ($targetActorIds as $id) {
                $candidates = $targetTokensByActor[$id] ?? $tokens->filter(fn ($token) => (int) ($token['actorId'] ?? 0) === $id);
                $distance = collect($candidates)
                    ->map(fn ($token) => hypot((float) $token['x'] - (float) $source['x'], (float) $token['y'] - (float) $source['y']) * 5 / $grid)->min();
                abort_unless($distance !== null && $distance <= $action['rangeFeet'], 422, 'Um alvo está fora do alcance configurado.');
            }
        }
        if (($action['effect']['target'] ?? null) === 'targets' && ($action['effect']['trigger'] ?? 'on-use') === 'on-use') {
            abort_unless(count($targetActorIds) > 0, 422, 'Selecione um alvo para receber o efeito.');
        }
        $system = $actor->system;
        $effectiveSystem = $effects->effectiveSystem($actor);
        abort_unless(ConditionRules::canAct($effectiveSystem), 422, 'A condição atual impede o personagem de executar ações.');
        $activeEffects = $actor->activeEffects()->get();
        $targetSystemForAttack = null;
        $distanceForAttack = null;
        if (count($targetActorIds) === 1 && $targetActorIds[0] !== $actor->id) {
            $targetForAttack = Actor::where('campaign_id', $scene->campaign_id)->find($targetActorIds[0]);
            if ($targetForAttack) {
                $targetSystemForAttack = $effects->effectiveSystem($targetForAttack);
                $sceneTokens = collect($scene->state['tokens'] ?? []);
                $originToken = $sceneTokens->first(fn ($token) => (int) ($token['actorId'] ?? 0) === $actor->id);
                $targetToken = collect($targetTokensByActor[$targetForAttack->id] ?? [])->first()
                    ?? $sceneTokens->first(fn ($token) => (int) ($token['actorId'] ?? 0) === $targetForAttack->id);
                if ($originToken && $targetToken) {
                    $distanceForAttack = hypot((float) $targetToken['x'] - (float) $originToken['x'],
                        (float) $targetToken['y'] - (float) $originToken['y']) * 5 / max(1, (float) ($scene->state['grid']['size'] ?? 70));
                }
            }
        }
        $attackMode = ConditionRules::attackMode($effectiveSystem, $targetSystemForAttack, $data['mode'] ?? 'normal', $distanceForAttack);
        if ($attackFormula = CombatRules::attackFormula($effectiveSystem, $action)) {
            $action['attackFormula'] = $attackFormula;
        }
        if ($damageFormula = CombatRules::damageFormula($effectiveSystem, $action)) {
            $action['damageFormula'] = $damageFormula;
        }
        $resourceBefore = ['slots' => $system['spells']['slots'] ?? [], 'resources' => $system['resources'] ?? [],
            'inspiration' => $system['inspiration'] ?? false, 'concentration' => $system['concentration'] ?? null];
        $slotLevel = $action['spellSlotLevel'] ?? null;
        $genericResource = isset($action['resourceId']) ? collect($system['resources'] ?? [])->firstWhere('id', $action['resourceId']) : null;
        $document = null;
        if (isset($action['documentId'])) {
            $document = ActorDocument::query()->where('actor_id', $actor->id)->lockForUpdate()->findOrFail($action['documentId']);
            $charges = $document->charges;
            $resourceBefore['documentCharges'] = ['id' => $document->id, 'charges' => $charges];
        }
        try {
            $resourceCosts = ResourcePool::quote($system, $action, $scene->campaign->ruleset, $document?->charges);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
        $beforePools = $resourceCosts ? ResourceAudit::snapshot($actor, $system) : [];
        $rolls = [];
        $appliedRules = [];
        try {
            foreach (['attackFormula' => 'attack', 'damageFormula' => 'damage', 'damageParts' => 'typed-damage', 'healingFormula' => 'heal'] as $field => $kind) {
                if ($kind === 'typed-damage') {
                    // Typed parts keep attack → damage → healing order in the action pipeline.
                    // Each part owns a stable roll ID and damage type for audit/retry.
                    foreach ($action['damageParts'] ?? [] as $index => $part) {
                        $adjusted = HouseRules::apply($part['formula'], $action['name'].' · dano '.($index + 1), $scene->campaign->house_rules ?? []);
                        $formula = $effects->applyFormulaModifier($adjusted['formula'], $activeEffects, 'roll.damage');
                        if (($rolls[0]['kind'] ?? null) === 'attack' && $rolls[0]['critical']) {
                            $formula = CombatRules::criticalFormula($formula);
                        }
                        $rolls[] = ['kind' => 'damage', 'damageType' => $part['damageType'], ...$ledger->roll($scene, $request->user(), [
                            'requestId' => $requestId, 'step' => 'damage:'.$index, 'context' => 'damage',
                            'actorId' => $actor->id, 'formula' => $formula,
                            'label' => $actor->name.' · '.$action['name'].' · '.$part['damageType'],
                            'houseRules' => $adjusted['rules'], 'visibility' => $action['visibility'] ?? 'public',
                        ])];
                        $appliedRules = array_merge($appliedRules, $adjusted['rules']);
                    }

                    continue;
                }
                $formula = trim((string) ($action[$field] ?? ''));
                if ($formula === '') {
                    continue;
                }
                // Transform the d20 before optional effect dice (e.g. Bless +d4).
                // RollLedger records the mode but must not apply it a second time.
                if ($kind === 'attack' && $attackMode['mode'] !== 'normal') {
                    abort_unless(preg_match('/^(?:1)?d20([+-]\d+)?$/i', $formula, $match), 422, 'Para escolher vantagem, use uma fórmula de ataque 1d20±K.');
                    $formula = ($attackMode['mode'] === 'advantage' ? '2d20kh1' : '2d20kl1').($match[1] ?? '');
                }
                $adjusted = HouseRules::apply($formula, $action['name'].' · '.match ($kind) {
                    'attack' => 'Ataque', 'heal' => 'Cura', default => 'Dano'
                }, $scene->campaign->house_rules ?? []);
                $formula = $adjusted['formula'];
                if ($kind !== 'heal') {
                    $formula = $effects->applyFormulaModifier($formula, $activeEffects, $kind === 'attack' ? 'roll.attack' : 'roll.damage');
                }
                if ($kind === 'damage' && ($rolls[0]['kind'] ?? null) === 'attack' && $rolls[0]['critical']) {
                    $formula = CombatRules::criticalFormula($formula);
                }
                $rolls[] = ['kind' => $kind, ...$ledger->roll($scene, $request->user(), [
                    'requestId' => $requestId, 'step' => $kind, 'context' => $kind,
                    'actorId' => $actor->id, 'formula' => $formula, 'mode' => $kind === 'attack' ? $attackMode['mode'] : 'normal',
                    'modePrepared' => $kind === 'attack' && $attackMode['mode'] !== 'normal',
                    'label' => $actor->name.' · '.$action['name'].' · '.$kind,
                    'houseRules' => $adjusted['rules'],
                    'visibility' => $action['visibility'] ?? 'public',
                ])];
                $appliedRules = array_merge($appliedRules, $adjusted['rules']);
            }
        } catch (InvalidArgumentException $e) {
            // Throw, never return: any earlier attack roll must roll back if a
            // later step (damage/healing) has an invalid formula.
            abort(422, $e->getMessage());
        }
        abort_unless(count($rolls) || isset($action['saveAbility']) || isset($action['effect']) || isset($action['spellSlotLevel'])
            || isset($action['resourceId']) || isset($action['documentId']), 422, 'Defina rolagem, cura, efeito ou custo antes de usar esta ação.');
        $system = ResourcePool::spend($system, $resourceCosts);
        if ($document) {
            $charges = $document->charges;
            $charges['value'] -= (int) ($action['chargeCost'] ?? 1);
            $document->charges = $charges;
            $document->save();
        }
        if ($action['concentration'] ?? false) {
            $system['concentration'] = ['id' => (string) Str::uuid(), 'name' => $action['name'],
                'visibility' => $action['visibility'] ?? 'public'];
        }
        $actor->system = $system;
        $actor->save();
        $system = $actor->system;
        $result = $rolls[0] ?? ['formula' => '', 'total' => 0, 'detail' => 'Ação sem rolagem; custos e efeitos registrados.', 'critical' => false, 'fumble' => false];
        $user = $request->user();
        $messageId = (string) Str::uuid();
        $effectIds = [];
        $actionEffect = isset($action['effect']) && is_array($action['effect']) ? $action['effect'] : null;
        if ($actionEffect && ($actionEffect['trigger'] ?? 'on-use') === 'on-use') {
            $effect = $actionEffect;
            if (($effect['target'] ?? 'targets') === 'self') {
                $effectActors = collect([$actor]);
            } else {
                $effectActors = Actor::query()->where('campaign_id', $scene->campaign_id)->whereIn('id', $targetActorIds)->get();
            }
            foreach ($effectActors as $targetActor) {
                $createdEffect = ActiveEffect::create([
                    'actor_id' => $targetActor->id,
                    'source_document_id' => $document?->id,
                    'name' => $effect['name'],
                    'duration' => $effect['duration'],
                    'modifiers' => $effect['modifiers'] ?? [],
                    'conditions' => $effect['conditions'] ?? [],
                    'source_label' => $actor->name.' · '.$action['name'],
                    'icon_url' => $effect['iconUrl'] ?? null,
                    'visibility' => $action['visibility'] ?? 'public',
                    'concentration_actor_id' => ($action['concentration'] ?? false) ? $actor->id : null,
                    'concentration_id' => ($action['concentration'] ?? false) ? ($system['concentration']['id'] ?? null) : null,
                    'metadata' => [
                        'actionMessageId' => $messageId,
                        'sourceActorId' => $actor->id,
                        'createdBy' => $user->id,
                    ],
                    'active' => true,
                ]);
                $effectIds[] = $createdEffect->id;
                $effects->breakIfIncapacitating($createdEffect);
                EffectAudit::record($createdEffect, 'created', null, $user->id, 'action:'.$messageId);
            }
        }
        $message = [
            'id' => $messageId,
            'userId' => $user->id,
            'userName' => $user->name,
            'type' => 'action',
            'actionKind' => $action['kind'] ?? 'attack',
            'visibility' => $action['visibility'] ?? 'public',
            'actionOrigin' => $action['origin'] ?? 'Ficha',
            'actionRevision' => $actionRevision,
            'sourceActorId' => $actor->id,
            'concentrationId' => ($action['concentration'] ?? false) ? ($system['concentration']['id'] ?? null) : null,
            'damageType' => $action['damageType'] ?? null,
            'save' => isset($action['saveAbility']) ? ['ability' => $action['saveAbility'], 'dc' => CombatRules::saveDc($effectiveSystem, $action) + (int) round($effects->rollModifier($activeEffects, 'spell.saveDc')), 'effect' => $action['saveEffect']] : null,
            'houseRules' => array_values(array_unique($appliedRules)),
            'targetActorIds' => $targetActorIds,
            'targetTokenIds' => $targetTokenIds,
            'targetMode' => $targetMode,
            'effectIds' => $effectIds,
            'effect' => $actionEffect,
            'sourceDocumentId' => $document?->id,
            'rolls' => $rolls,
            'label' => $actor->name.' · '.$action['name']
                .($slotLevel !== null ? ' · espaço nível '.$slotLevel.' consumido' : '')
                .(isset($action['resourceId']) ? ' · '.($action['resourceCost'] ?? $genericResource['defaultCost'] ?? 1).' '.($genericResource['name'] ?? 'Inspiração').' consumido' : ''),
            'formula' => $result['formula'],
            'total' => $result['total'],
            'detail' => $result['detail'],
            'text' => $action['description'] ?? null,
            'critical' => $result['critical'],
            'fumble' => $result['fumble'],
            'effectUrl' => isset($action['effectUrl']) && str_starts_with($action['effectUrl'], 'https://') ? $action['effectUrl'] : null,
            'imageUrl' => $action['imageUrl'] ?? null,
            'economy' => $action['economy'] ?? 'action',
            'rangeFeet' => $action['rangeFeet'] ?? null,
            'pipeline' => collect($rolls)->map(fn ($roll) => ['kind' => $roll['kind'], 'rollId' => $roll['id']])->all(),
            'createdAt' => now()->toIso8601String(),
        ];
        DB::table('action_records')->insert([
            'scene_id' => $scene->id, 'actor_id' => $actor->id, 'user_id' => $user->id,
            'request_id' => $requestId, 'message_id' => $message['id'], 'message' => json_encode($message),
            'input_hash' => $inputHash,
            'action_snapshot' => json_encode($actionSnapshot, JSON_THROW_ON_ERROR),
            'actor_revision' => $actionRevision,
            'resource_before' => json_encode($resourceBefore),
            'resource_after' => json_encode([
                'slots' => $system['spells']['slots'] ?? [],
                'resources' => $system['resources'] ?? [],
                'inspiration' => $system['inspiration'] ?? false,
                'concentration' => $system['concentration'] ?? null,
                ...($document ? ['documentCharges' => ['id' => $document->id, 'charges' => $document->fresh()->charges]] : []),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $state = $scene->state;
        $state['chat'][] = $message;
        $state['chat'] = array_slice($state['chat'], -200);
        $scene->state = $state;
        $scene->save();
        if ($resourceCosts) {
            ResourceAudit::record($actor, 'action', $beforePools, ResourceAudit::snapshot($actor, $system),
                $user->id, $messageId, null, null, 'action:'.$action['name']);
        }
        broadcast(new SceneUpdated($scene, 'chat'));

        return response()->json(['message' => $scene->messageFor($request->user(), $message), 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user())]);
    }
}
