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
use App\Support\Dnd\HouseRules;
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
        $inputHash = hash('sha256', json_encode([
            $actor->id, $data['actionId'], $data['mode'] ?? 'normal', $targetActorIds,
        ], JSON_THROW_ON_ERROR));
        $existing = DB::table('action_records')->where(['scene_id' => $scene->id, 'user_id' => $request->user()->id, 'request_id' => $requestId])->first();
        if ($existing) {
            abort_unless((int) $existing->actor_id === $actor->id, 409);
            abort_if($existing->input_hash !== null && ! hash_equals($existing->input_hash, $inputHash), 409, 'Esta chave já foi usada em outra ação ou seleção de alvos.');

            return response()->json(['message' => json_decode($existing->message, true), 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user())]);
        }
        $action = collect($actor->system['actions'] ?? [])->firstWhere('id', $data['actionId']);
        if (! $action) {
            abort(404, 'Ação não encontrada na ficha.');
        }
        CombatRules::validateAction($action);
        $targetMode = $action['target'] ?? null;
        if ($targetMode === 'self') {
            abort_unless(! $targetActorIds || $targetActorIds === [$actor->id], 422, 'Esta ação só pode mirar a própria ficha.');
            $targetActorIds = [$actor->id];
        } elseif ($targetMode === 'single') {
            abort_unless(count($targetActorIds) === 1, 422, 'Selecione exatamente um alvo.');
        } elseif ($targetMode === 'multiple') {
            abort_unless(count($targetActorIds) >= 1 && count($targetActorIds) <= (int) ($action['maxTargets'] ?? 50), 422, 'Revise a quantidade de alvos desta ação.');
        }
        if ($targetActorIds && ! $member->isGm()) {
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
                $distance = $tokens->filter(fn ($token) => (int) ($token['actorId'] ?? 0) === $id)
                    ->map(fn ($token) => hypot((float) $token['x'] - (float) $source['x'], (float) $token['y'] - (float) $source['y']) * 5 / $grid)->min();
                abort_unless($distance !== null && $distance <= $action['rangeFeet'], 422, 'Um alvo está fora do alcance configurado.');
            }
        }
        if (($action['effect']['target'] ?? null) === 'targets' && ($action['effect']['trigger'] ?? 'on-use') === 'on-use') {
            abort_unless(count($targetActorIds) > 0, 422, 'Selecione um alvo para receber o efeito.');
        }
        $system = $actor->system;
        $effectiveSystem = $effects->effectiveSystem($actor);
        $activeEffects = $actor->activeEffects()->get();
        if ($attackFormula = CombatRules::attackFormula($effectiveSystem, $action)) {
            $action['attackFormula'] = $attackFormula;
        }
        if ($damageFormula = CombatRules::damageFormula($effectiveSystem, $action)) {
            $action['damageFormula'] = $damageFormula;
        }
        $resourceBefore = ['slots' => $system['spells']['slots'] ?? [], 'resources' => $system['resources'] ?? [], 'concentration' => $system['concentration'] ?? null];
        $slotLevel = $action['spellSlotLevel'] ?? null;
        if ($slotLevel !== null) {
            abort_unless(is_int($slotLevel) && $slotLevel >= 1 && $slotLevel <= 9, 422, 'Nível de espaço inválido.');
            $slot = $system['spells']['slots'][$slotLevel] ?? null;
            abort_unless($slot && ($slot['used'] ?? 0) < ($slot['max'] ?? 0), 422, 'Não há espaço de magia disponível neste nível.');
        }
        $genericResource = null;
        if (isset($action['resourceId'])) {
            $resourceIndex = collect($system['resources'] ?? [])->search(fn ($resource) => ($resource['id'] ?? null) === $action['resourceId']);
            abort_unless($resourceIndex !== false, 422, 'O recurso configurado nesta ação não existe mais na ficha.');
            $genericResource = $system['resources'][$resourceIndex];
            $cost = (int) ($action['resourceCost'] ?? 1);
            $available = max(0, (int) ($genericResource['max'] ?? 0) - (int) ($genericResource['used'] ?? 0));
            abort_unless($available >= $cost, 422, 'Não há usos suficientes de '.$genericResource['name'].'.');
        }
        $document = null;
        if (isset($action['documentId'])) {
            $document = ActorDocument::query()->where('actor_id', $actor->id)->lockForUpdate()->findOrFail($action['documentId']);
            $charges = $document->charges;
            abort_unless($charges !== null, 422, 'Este documento não possui cargas configuradas.');
            $chargeCost = (int) ($action['chargeCost'] ?? 1);
            abort_unless((int) $charges['value'] >= $chargeCost, 422, 'Não há cargas suficientes em '.$document->name.'.');
            $resourceBefore['documentCharges'] = ['id' => $document->id, 'charges' => $charges];
        }
        $rolls = [];
        $appliedRules = [];
        try {
            foreach (['attackFormula' => 'attack', 'damageFormula' => 'damage', 'healingFormula' => 'heal'] as $field => $kind) {
                $formula = trim((string) ($action[$field] ?? ''));
                if ($formula === '') {
                    continue;
                }
                // Transform the d20 before optional effect dice (e.g. Bless +d4).
                // RollLedger records the mode but must not apply it a second time.
                if ($kind === 'attack' && ($data['mode'] ?? 'normal') !== 'normal') {
                    abort_unless(preg_match('/^(?:1)?d20([+-]\d+)?$/i', $formula, $match), 422, 'Para escolher vantagem, use uma fórmula de ataque 1d20±K.');
                    $formula = ($data['mode'] === 'advantage' ? '2d20kh1' : '2d20kl1').($match[1] ?? '');
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
                    'actorId' => $actor->id, 'formula' => $formula, 'mode' => $kind === 'attack' ? ($data['mode'] ?? 'normal') : 'normal',
                    'modePrepared' => $kind === 'attack' && ($data['mode'] ?? 'normal') !== 'normal',
                    'label' => $actor->name.' · '.$action['name'].' · '.$kind,
                    'houseRules' => $adjusted['rules'],
                ])];
                $appliedRules = array_merge($appliedRules, $adjusted['rules']);
            }
        } catch (InvalidArgumentException $e) {
            // Throw, never return: any earlier attack roll must roll back if a
            // later step (damage/healing) has an invalid formula.
            abort(422, $e->getMessage());
        }
        abort_unless(count($rolls) || isset($action['saveAbility']) || isset($action['effect']) || isset($action['resourceId']) || isset($action['documentId']), 422, 'Defina rolagem, cura, efeito ou custo antes de usar esta ação.');
        if ($slotLevel !== null) {
            $system['spells']['slots'][$slotLevel]['used'] = ($slot['used'] ?? 0) + 1;
        }
        if ($genericResource !== null) {
            $resourceIndex = collect($system['resources'])->search(fn ($resource) => ($resource['id'] ?? null) === $action['resourceId']);
            $system['resources'][$resourceIndex]['used'] = ($genericResource['used'] ?? 0) + (int) ($action['resourceCost'] ?? 1);
        }
        if ($document) {
            $charges = $document->charges;
            $charges['value'] = max(0, (int) $charges['value'] - (int) ($action['chargeCost'] ?? 1));
            $document->charges = $charges;
            $document->save();
        }
        if ($action['concentration'] ?? false) {
            $system['concentration'] = ['id' => (string) Str::uuid(), 'name' => $action['name']];
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
                    'metadata' => [
                        'actionMessageId' => $messageId,
                        'sourceActorId' => $actor->id,
                        'createdBy' => $user->id,
                    ],
                    'active' => true,
                ]);
                $effectIds[] = $createdEffect->id;
            }
        }
        $message = [
            'id' => $messageId,
            'userId' => $user->id,
            'userName' => $user->name,
            'type' => 'action',
            'actionKind' => $action['kind'] ?? 'attack',
            'sourceActorId' => $actor->id,
            'damageType' => $action['damageType'] ?? null,
            'save' => isset($action['saveAbility']) ? ['ability' => $action['saveAbility'], 'dc' => CombatRules::saveDc($effectiveSystem, $action) + (int) round($effects->rollModifier($activeEffects, 'spell.saveDc')), 'effect' => $action['saveEffect']] : null,
            'houseRules' => array_values(array_unique($appliedRules)),
            'targetActorIds' => $targetActorIds,
            'targetMode' => $targetMode,
            'effectIds' => $effectIds,
            'effect' => $actionEffect,
            'sourceDocumentId' => $document?->id,
            'rolls' => $rolls,
            'label' => $actor->name.' · '.$action['name']
                .($slotLevel !== null ? ' · espaço nível '.$slotLevel.' consumido' : '')
                .($genericResource !== null ? ' · '.($action['resourceCost'] ?? 1).' '.($genericResource['name'] ?? 'recurso').' consumido' : ''),
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
            'resource_before' => json_encode($resourceBefore),
            'resource_after' => json_encode([
                'slots' => $system['spells']['slots'] ?? [],
                'resources' => $system['resources'] ?? [],
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
        broadcast(new SceneUpdated($scene, 'chat'));

        return response()->json(['message' => $message, 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user())]);
    }
}
