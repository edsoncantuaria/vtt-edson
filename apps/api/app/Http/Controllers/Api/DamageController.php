<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Game\Dice\RollLedger;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\Scene;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\CombatRules;
use App\Support\Dnd\EffectAudit;
use App\Support\Dnd\ResourceAudit;
use App\Support\Dnd\VitalityCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DamageController extends Controller
{
    use AuthorizesScene;

    public function __construct(private readonly ActiveEffectEngine $effects) {}

    private function target(Request $request, Scene $scene): Actor
    {
        $member = $request->isMethodSafe() ? $this->requireMember($request, $scene) : $this->requireParticipant($request, $scene);
        $data = $request->validate(['actorId' => ['required', 'integer']]);
        $query = Actor::where('campaign_id', $scene->campaign_id);
        if (! $request->isMethodSafe()) {
            $query->lockForUpdate();
        }
        $actor = $query->findOrFail($data['actorId']);
        abort_unless($member->isGm() || $actor->isOwnedBy($request->user()), 403);

        return $actor;
    }

    private function source(Request $request, Scene $scene, string $messageId): array
    {
        $record = DB::table('action_records')->where(['scene_id' => $scene->id, 'message_id' => $messageId])->first();
        $message = $record ? json_decode($record->message, true) : collect($scene->state['chat'] ?? [])->firstWhere('id', $messageId);
        abort_unless($message, 404, 'Ação não encontrada.');
        abort_if(($message['visibility'] ?? 'public') === 'gm' && ! $scene->campaign->canManage($request->user()), 404, 'Ação não encontrada.');

        return [$message, $record];
    }

    private function key(Scene $scene, Actor $actor, string $messageId): array
    {
        return ['scene_id' => $scene->id, 'actor_id' => $actor->id, 'message_id' => $messageId];
    }

    /** The action record, not a later map selection, owns every batch target. */
    private function batchSource(Request $request, Scene $scene, string $messageId, bool $requireActive = true): array
    {
        $this->requireGm($request, $scene);
        [$message, $record] = $this->source($request, $scene, $messageId);
        abort_unless($record && isset($message['save']), 422, 'Esta ação não tem salvaguardas registradas.');
        abort_if($requireActive && $record->undone, 409, 'Esta ação já foi desfeita.');
        $ids = array_values(array_unique(array_map('intval', $message['targetActorIds'] ?? [])));
        abort_unless(count($ids) >= 1 && count($ids) <= 50, 422, 'A ação precisa de alvos confirmados para resolver em lote.');

        return [$message, $record, $ids];
    }

    /** Entire, GM-only per-target status survives chat truncation and reload. */
    public function batchIndex(Request $request, Scene $scene, string $messageId)
    {
        [$message, $record, $ids] = $this->batchSource($request, $scene, $messageId, false);
        $actors = Actor::where('campaign_id', $scene->campaign_id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($actors->count() === count($ids), 409, 'Um dos alvos da ação não existe mais.');
        $saves = json_decode($record->saves ?? '{}', true);
        $applications = DB::table('damage_applications')->where('scene_id', $scene->id)
            ->where('message_id', $messageId)->whereIn('actor_id', $ids)->get()->keyBy('actor_id');
        $hasDamage = collect($message['rolls'] ?? [])->contains('kind', 'damage');
        $rows = [];
        foreach ($ids as $id) {
            $actor = $actors->get($id);
            $save = $saves[$id] ?? null;
            $operation = $applications->get($id);
            $effective = $this->effects->effectiveSystem($actor);
            $preview = $hasDamage
                ? CombatRules::damage($message, $effective, $save, targetActorId: $actor->id)
                : ['damage' => 0, 'hit' => null, 'pendingSave' => $save === null, 'steps' => ['Ação sem dano direto']];
            $rows[] = [
                'actorId' => $id, 'name' => $actor->name, 'type' => $actor->type,
                'ownerUserId' => $actor->owner_user_id, 'save' => $save, 'preview' => $preview,
                'application' => $operation ? ['undone' => (bool) $operation->undone,
                    'resolution' => json_decode($operation->resolution ?? '{}', true)] : null,
            ];
        }

        $operations = DB::table('action_batch_operations')->where(['scene_id' => $scene->id, 'message_id' => $messageId])
            ->orderBy('id')->get(['user_id', 'request_id', 'kind', 'target_actor_ids', 'created_at']);
        $authorIds = $operations->pluck('user_id')->all();
        foreach ($rows as $row) {
            if (isset($row['save']['userId'])) {
                $authorIds[] = $row['save']['userId'];
            }
            foreach ($row['save']['history'] ?? [] as $old) {
                if (isset($old['userId'])) {
                    $authorIds[] = $old['userId'];
                }
            }
        }
        $authors = DB::table('users')->whereIn('id', array_unique($authorIds))->pluck('name', 'id');
        foreach ($rows as &$row) {
            if ($row['save'] !== null) {
                $row['save']['userName'] = $authors[$row['save']['userId'] ?? null] ?? 'Usuário';
                foreach ($row['save']['history'] ?? [] as $index => $old) {
                    $row['save']['history'][$index]['userName'] = $authors[$old['userId'] ?? null] ?? 'Usuário';
                }
            }
        }
        unset($row);

        return response()->json([
            'save' => $message['save'], 'rows' => $rows, 'actionUndone' => (bool) $record->undone,
            'operations' => $operations->map(fn ($entry) => [
                'requestId' => $entry->request_id, 'kind' => $entry->kind,
                'actorIds' => json_decode($entry->target_actor_ids, true),
                'userId' => $entry->user_id, 'userName' => $authors[$entry->user_id] ?? 'Usuário',
                'createdAt' => $entry->created_at,
            ])->all(),
        ]);
    }

    /** Refuse reused keys with changed payloads; the scene's write lock serializes concurrent batches. */
    private function batchReplay(Request $request, Scene $scene, string $messageId, string $kind, string $requestId, array $targets): bool
    {
        $hash = hash('sha256', json_encode([$messageId, $kind, $targets], JSON_THROW_ON_ERROR));
        $existing = DB::table('action_batch_operations')->where([
            'scene_id' => $scene->id, 'user_id' => $request->user()->id, 'request_id' => $requestId,
        ])->first();
        if ($existing) {
            abort_unless(hash_equals($existing->input_hash, $hash), 409, 'Esta chave já corresponde a outra operação em lote.');

            return true;
        }
        DB::table('action_batch_operations')->insert([
            'scene_id' => $scene->id, 'user_id' => $request->user()->id,
            'request_id' => $requestId, 'message_id' => $messageId, 'kind' => $kind,
            'input_hash' => $hash, 'target_actor_ids' => json_encode(array_column($targets, 'actorId'), JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return false;
    }

    public function batchSave(Request $request, Scene $scene, string $messageId, DiceRoller $dice, RollLedger $ledger)
    {
        return DB::transaction(function () use ($request, $scene, $messageId, $dice, $ledger) {
            [, , $ids] = $this->batchSource($request, $scene, $messageId);
            $data = $request->validate([
                'requestId' => ['required', 'uuid'], 'targets' => ['required', 'array', 'min:1', 'max:50'],
                'targets.*' => ['required', 'array:actorId,mode,bonus,decision,reason'],
                'targets.*.actorId' => ['required', 'integer', 'distinct'],
                'targets.*.mode' => ['sometimes', 'in:normal,advantage,disadvantage'],
                'targets.*.bonus' => ['sometimes', 'integer', 'between:-30,30'],
                'targets.*.decision' => ['sometimes', 'in:roll,success,failure'],
                'targets.*.reason' => ['required_if:targets.*.decision,success,failure', 'string', 'max:240'],
            ]);
            $targets = $data['targets'];
            usort($targets, fn ($a, $b) => $a['actorId'] <=> $b['actorId']);
            abort_unless(! array_diff(array_column($targets, 'actorId'), $ids), 422, 'A operação inclui um alvo fora da ação.');
            foreach ($targets as $target) {
                abort_if(($target['decision'] ?? 'roll') !== 'roll' && trim($target['reason'] ?? '') === '', 422, 'Decisões manuais exigem motivo.');
            }
            $replayed = $this->batchReplay($request, $scene, $messageId, 'save', $data['requestId'], $targets);
            if (! $replayed) {
                foreach ($targets as $target) {
                    $request->replace($target);
                    $this->saveLocked($request, $scene, $messageId, $dice, $ledger);
                }
            }

            return response()->json(['replayed' => $replayed, 'actorIds' => array_column($targets, 'actorId')]);
        });
    }

    public function batchApply(Request $request, Scene $scene, string $messageId)
    {
        return DB::transaction(function () use ($request, $scene, $messageId) {
            [, , $ids] = $this->batchSource($request, $scene, $messageId);
            $data = $request->validate([
                'requestId' => ['required', 'uuid'], 'actorIds' => ['required', 'array', 'min:1', 'max:50'],
                'actorIds.*' => ['required', 'integer', 'distinct'],
            ]);
            $targetIds = $data['actorIds'];
            sort($targetIds);
            abort_unless(! array_diff($targetIds, $ids), 422, 'A operação inclui um alvo fora da ação.');
            $targets = array_map(fn ($id) => ['actorId' => $id], $targetIds);
            $replayed = $this->batchReplay($request, $scene, $messageId, 'apply', $data['requestId'], $targets);
            if (! $replayed) {
                foreach ($targetIds as $id) {
                    $request->replace(['actorId' => $id]);
                    $this->storeLocked($request, $scene, $messageId);
                }
            }

            return response()->json(['replayed' => $replayed, 'actorIds' => $targetIds]);
        });
    }

    public function history(Request $request, Scene $scene)
    {
        $this->requireMember($request, $scene);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $query = DB::table('action_records')->where('scene_id', $scene->id);
        if (! $scene->campaign->canManage($request->user())) {
            $query->where(fn ($q) => $q->whereNull('message->visibility')->orWhere('message->visibility', '!=', 'gm'));
        }
        $records = $query->orderByDesc('id')->paginate(30);

        $messages = collect($records->items())->map(fn ($record) => [...json_decode($record->message, true), 'undone' => (bool) $record->undone])->all();

        return response()->json(['messages' => $scene->messagesFor($request->user(), $messages), 'lastPage' => $records->lastPage()]);
    }

    public function show(Request $request, Scene $scene, string $messageId)
    {
        $actor = $this->target($request, $scene);
        [$message, $record] = $this->source($request, $scene, $messageId);
        $save = $record ? (json_decode($record->saves ?? '{}', true)[$actor->id] ?? null) : null;
        $operation = DB::table('damage_applications')->where($this->key($scene, $actor, $messageId))->first();
        $hasDamage = collect($message['rolls'] ?? [])->contains('kind', 'damage');
        $effect = $message['effect'] ?? null;
        $needsResolution = $hasDamage || isset($message['save']) || (is_array($effect) && in_array($effect['trigger'] ?? 'on-use', ['on-hit', 'on-failed-save'], true));
        if (! empty($message['targetActorIds'])) {
            abort_unless(in_array($actor->id, $message['targetActorIds'] ?? [], true), 422, 'Este ator não pertence aos alvos confirmados da ação.');
        }
        $attack = $needsResolution && ! $hasDamage ? CombatRules::attack($message, $this->effects->effectiveSystem($actor)) : null;

        return response()->json([
            'preview' => ! $needsResolution ? null : ($hasDamage
                ? CombatRules::damage($message, $this->effects->effectiveSystem($actor), $save, targetActorId: $actor->id)
                : ['damage' => 0, 'steps' => ['Ação sem dano direto'], 'hit' => $attack['hit'] ?? null, 'attack' => $attack, 'pendingSave' => isset($message['save']) && $save === null, 'manual' => false]),
            'save' => $save,
            'application' => $operation ? ['operationId' => $operation->id, 'undone' => (bool) $operation->undone,
                'before' => json_decode($operation->before, true), 'after' => json_decode($operation->after, true),
                'resolution' => json_decode($operation->resolution ?? '{}', true)] : null,
            'actionUndone' => (bool) ($record->undone ?? false),
        ]);
    }

    public function save(Request $request, Scene $scene, string $messageId, DiceRoller $dice, RollLedger $ledger)
    {
        return DB::transaction(fn () => $this->saveLocked($request, $scene, $messageId, $dice, $ledger));
    }

    private function saveLocked(Request $request, Scene $scene, string $messageId, DiceRoller $dice, RollLedger $ledger)
    {
        $actor = $this->target($request, $scene);
        $options = $request->validate([
            'mode' => ['sometimes', 'in:normal,advantage,disadvantage'],
            'bonus' => ['sometimes', 'integer', 'between:-30,30'],
            'decision' => ['sometimes', 'in:roll,success,failure'],
            'reason' => ['required_if:decision,success,failure', 'string', 'max:240'],
        ]);
        [$message, $record] = $this->source($request, $scene, $messageId);
        if ($message['targetMode'] ?? null) {
            abort_unless(in_array($actor->id, $message['targetActorIds'] ?? [], true), 422, 'Este ator não pertence aos alvos confirmados da ação.');
        }
        abort_unless($record && isset($message['save']) && ! $record->undone, 422, 'Esta ação não tem salvaguarda pendente.');
        $saves = json_decode($record->saves ?? '{}', true);
        $previous = $saves[$actor->id] ?? null;
        if ($previous && ($options['decision'] ?? 'roll') === 'roll') {
            return response()->json(['save' => $previous]);
        }
        if ($previous) {
            abort_unless($scene->campaign->canManage($request->user()), 403, 'Apenas o mestre pode substituir uma salvaguarda já resolvida.');
        }
        abort_if(DB::table('damage_applications')->where($this->key($scene, $actor, $messageId))->exists(), 409, 'O dano já foi decidido.');
        $decision = $options['decision'] ?? 'roll';
        if ($decision !== 'roll') {
            $gm = $scene->campaign->canManage($request->user());
            abort_unless($gm || ($decision === 'failure' && $scene->campaign->ruleset === '5e-2024'), 403, 'Esta decisão requer o mestre nesta edição.');
            $result = ['ability' => $message['save']['ability'], 'dc' => $message['save']['dc'], 'success' => $decision === 'success', 'reason' => $options['reason'], 'manual' => true];
        } else {
            try {
                $effectiveSystem = $this->effects->effectiveSystem($actor);
                $options['effectFormula'] = $this->effects->formulaSuffix($actor->activeEffects()->get(), 'roll.save');
                $label = 'Salvaguarda de '.$message['save']['ability'].' · '.$message['label'];
                $result = CombatRules::save($effectiveSystem, $message['save']['ability'], $message['save']['dc'], $options, $dice, $scene->campaign->house_rules ?? [], $label,
                    fn ($formula, $rules, $mode) => $ledger->roll($scene, $request->user(), [
                        'requestId' => $messageId, 'step' => 'save:'.$actor->id, 'context' => 'save',
                        'actorId' => $actor->id, 'formula' => $formula, 'mode' => $mode, 'modePrepared' => true,
                        'label' => $label, 'houseRules' => $rules, 'visibility' => 'gm',
                    ]));
            } catch (InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }
        }
        if ($previous) {
            $history = $previous['history'] ?? [];
            unset($previous['history']);
            abort_if(count($history) >= 20, 422, 'Limite de decisões atingido para esta salvaguarda.');
            $result['history'] = [...$history, $previous];
        }
        $result['userId'] = $request->user()->id;
        $result['createdAt'] = now()->toIso8601String();
        $saves[$actor->id] = $result;
        DB::table('action_records')->where('id', $record->id)->update(['saves' => json_encode($saves), 'updated_at' => now()]);
        broadcast(new SceneUpdated($scene, 'resolution'));

        return response()->json(['save' => $result]);
    }

    public function store(Request $request, Scene $scene, string $messageId)
    {
        return DB::transaction(fn () => $this->storeLocked($request, $scene, $messageId));
    }

    private function storeLocked(Request $request, Scene $scene, string $messageId)
    {
        $actor = $this->target($request, $scene);
        $data = $request->validate([
            'factor' => ['sometimes', 'in:0,0.5,1,2'],
            'hitDecision' => ['sometimes', 'in:auto,hit,miss'],
            'damageOverride' => ['sometimes', 'integer', 'between:0,100000'],
            'correct' => ['sometimes', 'boolean'],
            'requestId' => ['required_if:correct,true', 'uuid'],
            'undo' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'string', 'max:240'],
        ]);
        $override = isset($data['factor']) || isset($data['damageOverride']) || in_array($data['hitDecision'] ?? 'auto', ['hit', 'miss'], true);
        abort_if(isset($data['factor'], $data['damageOverride']), 422, 'Escolha um único tipo de correção do dano.');
        if ($override && ! ($data['undo'] ?? false)) {
            abort_unless($scene->campaign->canManage($request->user()), 403, 'Somente o mestre pode corrigir acertos e dano.');
            abort_unless(trim($data['reason'] ?? '') !== '', 422, 'Informe o motivo da correção.');
        }
        $key = $this->key($scene, $actor, $messageId);
        $operation = DB::table('damage_applications')->where($key)->first();
        if ($data['correct'] ?? false) {
            return $this->correctLocked($request, $scene, $messageId, $actor, $operation, $data);
        }
        if ($operation && ! $operation->undone && ! ($data['undo'] ?? false) && $override) {
            $prior = json_decode($operation->resolution ?? '{}', true);
            abort_if(
                (isset($data['factor']) ? (float) $data['factor'] : null) !== (isset($prior['factor']) ? (float) $prior['factor'] : null)
                || ($data['damageOverride'] ?? null) !== ($prior['damageOverride'] ?? null)
                || ($data['hitDecision'] ?? 'auto') !== ($prior['hitDecision'] ?? 'auto')
                || ($data['reason'] ?? null) !== ($prior['reason'] ?? null),
                409, 'Este dano já foi confirmado com outra decisão; não é possível sobrescrever a resolução.');
        }
        $system = $actor->system;
        if ($data['undo'] ?? false) {
            abort_unless($operation !== null, 404);
            if (! $operation->undone) {
                [$originalMessage] = $this->source($request, $scene, $messageId);
                $resolution = json_decode($operation->resolution ?? '{}', true);
                abort_unless(($system['hp'] ?? []) == json_decode($operation->after, true), 409, 'Os PV mudaram depois deste dano. Ajuste a ficha manualmente.');
                abort_if($actor->type === 'character' && (int) ($system['hp']['value'] ?? -1) === 0
                    && (((int) ($system['deathSaves']['success'] ?? 0) > 0) || ((int) ($system['deathSaves']['failure'] ?? 0) > 0)
                        || array_intersect($system['conditions'] ?? [], ['morto', 'estabilizado', 'dead', 'stabilized'])),
                    409, 'Já houve salvaguardas contra morte; não é seguro desfazer este dano automaticamente.');
                if (array_key_exists('concentrationAfter', $resolution)) {
                    abort_unless(($system['concentration'] ?? null) == $resolution['concentrationAfter'], 409, 'A concentração mudou depois deste dano.');
                    $system['concentration'] = $resolution['concentrationBefore'];
                }
                $system['hp'] = json_decode($operation->before, true);
                if (isset($resolution['effectId'])) {
                    $effectToUndo = ActiveEffect::query()->whereKey($resolution['effectId'])->where('actor_id', $actor->id)->first();
                    if ($effectToUndo) {
                        EffectAudit::record($effectToUndo, 'removed', $effectToUndo->toArray(), $request->user()->id, 'damage-undo');
                        $effectToUndo->delete();
                    }
                }
                $resolution['undoneBy'] = $request->user()->id;
                $resolution['undoneAt'] = now()->toIso8601String();
                DB::table('damage_applications')->where($key)->update(['undone' => true, 'resolution' => json_encode($resolution)]);
                $this->resolutionChat($scene, $request, $originalMessage, $resolution, true);
            }
        } elseif (! $operation) {
            [$message, $record] = $this->source($request, $scene, $messageId);
            if (! empty($message['targetActorIds'])) {
                abort_unless(in_array($actor->id, $message['targetActorIds'] ?? [], true), 422, 'Este ator não pertence aos alvos confirmados da ação.');
            }
            abort_if($record?->undone, 409, 'Esta ação foi desfeita.');
            $save = $record ? (json_decode($record->saves ?? '{}', true)[$actor->id] ?? null) : null;
            $effectiveSystem = $this->effects->apply($system, $actor->activeEffects()->get());
            $hasDamage = collect($message['rolls'] ?? [])->contains('kind', 'damage');
            if ($hasDamage) {
                $resolution = CombatRules::damage($message, $effectiveSystem, $save, isset($data['factor']) ? (float) $data['factor'] : null,
                    $data['hitDecision'] ?? 'auto', $data['damageOverride'] ?? null, $actor->id);
            } else {
                $attack = CombatRules::attack($message, $effectiveSystem);
                abort_if(($data['hitDecision'] ?? 'auto') !== 'auto' && ! $attack, 422, 'Esta ação não tem ataque.');
                if ($attack) {
                    $attack['automaticHit'] = $attack['hit'];
                    if (($data['hitDecision'] ?? 'auto') !== 'auto') {
                        $attack['hit'] = $data['hitDecision'] === 'hit';
                    }
                }
                abort_if(isset($data['damageOverride']) && $data['damageOverride'] > 0, 422, 'Esta ação não rolou dano.');
                $resolution = [
                    'damage' => 0, 'steps' => ['Ação sem dano direto', ...(($data['hitDecision'] ?? 'auto') !== 'auto' ? ['Acerto corrigido pelo mestre'] : [])], 'hit' => $attack['hit'] ?? null, 'attack' => $attack,
                    'hitDecision' => $data['hitDecision'] ?? 'auto', 'pendingSave' => isset($message['save']) && $save === null, 'manual' => $override,
                ];
            }
            abort_if($resolution['pendingSave'], 422, 'Resolva a salvaguarda ou escolha uma decisão manual.');
            $before = $system['hp'] ?? null;
            abort_unless(is_array($before) && isset($before['value'], $before['max']) && is_numeric($before['value']) && is_numeric($before['max']), 422, 'Revise os PV da ficha.');
            $damage = $resolution['damage'];
            $hpEffect = VitalityCalculator::applyDamage($before, $damage);
            $system['hp'] = $hpEffect['after'];
            $resolution['hpEffect'] = $hpEffect;
            $resolution['steps'] = [...$resolution['steps'], ...$hpEffect['steps']];
            $resolution['sources'] = array_values(array_unique([...($resolution['sources'] ?? []), ...$hpEffect['sources']]));
            $resolution['concentrationBefore'] = $system['concentration'] ?? null;
            if ($damage > 0 && $resolution['concentrationBefore']) {
                if ($system['hp']['value'] === 0) {
                    $system['concentration'] = null;
                    $resolution['steps'][] = 'Concentração encerrada: 0 PV';
                } else {
                    $resolution['concentrationDc'] = CombatRules::concentrationDc($damage, $scene->campaign->ruleset);
                }
            }
            $resolution['concentrationAfter'] = $system['concentration'] ?? null;
            $resolution['userId'] = $request->user()->id;
            $resolution['createdAt'] = now()->toIso8601String();
            $resolution['reason'] = $data['reason'] ?? null;
            $resolution['factor'] = isset($data['factor']) ? (float) $data['factor'] : null;
            $resolution['damageOverride'] = $data['damageOverride'] ?? null;
            $resolution['save'] = $save;
            $effect = is_array($message['effect'] ?? null) ? $message['effect'] : null;
            $trigger = $effect['trigger'] ?? 'on-use';
            $shouldApplyEffect = $effect && (
                ($trigger === 'on-hit' && ($resolution['hit'] ?? null) === true)
                || ($trigger === 'on-failed-save' && is_array($save) && ! ($save['success'] ?? false))
            );
            if ($shouldApplyEffect) {
                $existingEffect = ActiveEffect::query()->where('actor_id', $actor->id)->where('metadata->actionMessageId', $messageId)->first();
                if (! $existingEffect) {
                    $existingEffect = ActiveEffect::create([
                        'actor_id' => $actor->id,
                        'source_document_id' => $message['sourceDocumentId'] ?? null,
                        'name' => $effect['name'],
                        'duration' => $effect['duration'],
                        'modifiers' => $effect['modifiers'] ?? [],
                        'conditions' => $effect['conditions'] ?? [],
                        'source_label' => $message['label'] ?? 'Ação',
                        'icon_url' => $effect['iconUrl'] ?? null,
                        'visibility' => $message['visibility'] ?? 'public',
                        'concentration_actor_id' => isset($message['concentrationId']) ? ($message['sourceActorId'] ?? null) : null,
                        'concentration_id' => $message['concentrationId'] ?? null,
                        'metadata' => ['actionMessageId' => $messageId, 'sourceActorId' => $message['sourceActorId'] ?? null, 'createdBy' => $request->user()->id],
                        'active' => true,
                    ]);
                    $this->effects->breakIfIncapacitating($existingEffect);
                    EffectAudit::record($existingEffect, 'created', null, $request->user()->id, 'action:'.$messageId);
                }
                $resolution['effectId'] = $existingEffect->id;
                $resolution['steps'][] = 'Efeito aplicado: '.$effect['name'];
            }
            DB::table('damage_applications')->insert([...$key, 'before' => json_encode($before), 'after' => json_encode($system['hp']), 'resolution' => json_encode($resolution), 'undone' => false]);
            $this->resolutionChat($scene, $request, $message, $resolution, false);
        }
        $actor->system = $system;
        $actor->save();
        broadcast(new SceneUpdated($scene, 'resolution'));

        return response()->json(['actor' => $actor->toPayload(), 'undone' => (bool) ($data['undo'] ?? $operation->undone ?? false)]);
    }

    /** A separate, explicit correction of a finalized damage-only attack. Previous decisions remain in the ledger. */
    private function correctLocked(Request $request, Scene $scene, string $messageId, Actor $actor, ?object $operation, array $data)
    {
        abort_unless($scene->campaign->canManage($request->user()), 403);
        abort_unless($operation && ! $operation->undone, 422, 'Confirme a aplicação original antes de corrigir.');
        abort_unless(isset($data['damageOverride']), 422, 'Informe o dano corrigido.');
        abort_unless(! ($data['undo'] ?? false) && ! isset($data['factor']), 422, 'Correção não pode ser combinada com desfazer ou fator.');
        [$message, $record] = $this->source($request, $scene, $messageId);
        abort_if($record?->undone, 409, 'A ação original foi desfeita.');
        $resolution = json_decode($operation->resolution ?? '{}', true);
        $corrections = $resolution['corrections'] ?? [];
        $decision = $data['hitDecision'] ?? $resolution['hitDecision'] ?? 'auto';
        $reason = trim($data['reason'] ?? '');
        $hash = hash('sha256', json_encode([(int) $data['damageOverride'], $decision, $reason], JSON_THROW_ON_ERROR));
        foreach ($corrections as $correction) {
            if ($correction['requestId'] === $data['requestId']) {
                abort_unless(hash_equals($correction['hash'], $hash), 409, 'A chave já foi usada para outra correção.');

                return response()->json(['actor' => $actor->toPayload(), 'corrected' => true, 'replayed' => true]);
            }
        }
        abort_unless(count($corrections) < 20, 422, 'Limite de correções desta ação atingido.');
        // Recalculating an effect, resolved save or concentration would affect other
        // gameplay entities. Do not silently rewrite them after confirmation.
        abort_if(isset($message['save']) || isset($resolution['effectId'])
            || ($resolution['concentrationBefore'] ?? null) !== null
            || in_array($message['effect']['trigger'] ?? 'on-use', ['on-hit', 'on-failed-save'], true),
            409, 'Esta resolução possui efeito, salvaguarda ou concentração; não pode ser corrigida isoladamente.');
        abort_unless(collect($message['rolls'] ?? [])->contains('kind', 'damage'), 422, 'Esta ação não possui rolagem de dano para corrigir.');
        $after = json_decode($operation->after, true);
        abort_unless(($actor->system['hp'] ?? null) == $after, 409, 'Os PV mudaram depois desta ação; revisão manual necessária.');
        $attack = $resolution['attack'] ?? null;
        abort_if($decision !== 'auto' && ! $attack, 422, 'Esta ação não possui ataque.');
        $automaticHit = $attack['automaticHit'] ?? $attack['hit'] ?? null;
        $hit = $decision === 'auto' ? $automaticHit : $decision === 'hit';
        abort_if($decision === 'miss' && (int) $data['damageOverride'] > 0, 422, 'Um erro decidido não pode aplicar dano positivo.');
        $before = json_decode($operation->before, true);
        $amount = (int) $data['damageOverride'];
        $hpEffect = VitalityCalculator::applyDamage($before, $amount);
        $corrected = $hpEffect['after'];
        $current = $actor->system;
        abort_if($actor->type === 'character' && (int) ($after['value'] ?? -1) === 0 && $corrected['value'] > 0
            && (((int) ($current['deathSaves']['success'] ?? 0) > 0) || ((int) ($current['deathSaves']['failure'] ?? 0) > 0)
                || array_intersect($current['conditions'] ?? [], ['morto', 'estabilizado', 'dead', 'stabilized'])),
            409, 'Já houve desfecho ou salvaguardas contra morte; revise a ficha antes de corrigir um golpe letal.');
        $corrections[] = [
            'requestId' => $data['requestId'], 'hash' => $hash,
            'previousDamage' => $resolution['damage'], 'damage' => $amount,
            'previousHit' => $resolution['hit'] ?? null, 'hit' => $hit,
            'reason' => $reason, 'userId' => $request->user()->id, 'createdAt' => now()->toIso8601String(),
        ];
        $resolution['corrections'] = $corrections;
        $resolution['damage'] = $amount;
        $resolution['damageOverride'] = $amount;
        $resolution['hitDecision'] = $decision;
        $resolution['hit'] = $hit;
        if ($attack) {
            $attack['automaticHit'] = $automaticHit;
            $attack['hit'] = $hit;
            $resolution['attack'] = $attack;
        }
        $resolution['manual'] = true;
        $resolution['reason'] = $reason;
        $resolution['steps'][] = 'Correção posterior do mestre: '.$amount.' PV (sem alterar rolagem original)';
        $resolution['hpEffect'] = $hpEffect;
        $resolution['steps'] = [...$resolution['steps'], ...$hpEffect['steps']];
        $resolution['sources'] = array_values(array_unique([...($resolution['sources'] ?? []), ...$hpEffect['sources'], 'Decisão auditada do mestre: correção posterior']));
        $system = $current;
        $system['hp'] = $corrected;
        $actor->system = $system;
        $actor->save();
        DB::table('damage_applications')->where('id', $operation->id)->update([
            'after' => json_encode($corrected, JSON_THROW_ON_ERROR),
            'resolution' => json_encode($resolution, JSON_THROW_ON_ERROR),
        ]);
        $this->resolutionChat($scene, $request, $message, $resolution, false, true);
        broadcast(new SceneUpdated($scene, 'resolution'));

        return response()->json(['actor' => $actor->toPayload(), 'corrected' => true, 'replayed' => false]);
    }

    /** Public result deliberately does not disclose a private target's CA or remaining PV. */
    private function resolutionChat(Scene $scene, Request $request, array $message, array $resolution, bool $undo, bool $correction = false): void
    {
        $verdict = ($resolution['hit'] ?? null) === true ? 'Acerto' : (($resolution['hit'] ?? null) === false ? 'Erro' : 'Ação resolvida');
        $text = $undo
            ? 'Correção: dano/efeito da ação desfeito.'
            : ($correction ? 'Correção posterior do mestre · ' : '').$verdict.' · '.(int) ($resolution['damage'] ?? 0).' PV de dano confirmados.'
                .(! empty($resolution['manual']) ? ' Ajuste do mestre registrado.' : '');
        $state = $scene->state;
        $state['chat'][] = [
            'id' => (string) Str::uuid(), 'userId' => $request->user()->id,
            'userName' => $request->user()->name, 'type' => 'text',
            'visibility' => $message['visibility'] ?? 'public',
            'text' => $text, 'createdAt' => now()->toIso8601String(),
        ];
        $state['chat'] = array_slice($state['chat'], -200);
        $scene->state = $state;
        $scene->save();
    }

    public function concentration(Request $request, Scene $scene, string $messageId, DiceRoller $dice, RollLedger $ledger)
    {
        return DB::transaction(fn () => $this->concentrationLocked($request, $scene, $messageId, $dice, $ledger));
    }

    private function concentrationLocked(Request $request, Scene $scene, string $messageId, DiceRoller $dice, RollLedger $ledger)
    {
        $actor = $this->target($request, $scene);
        $options = $request->validate(['mode' => ['sometimes', 'in:normal,advantage,disadvantage'], 'bonus' => ['sometimes', 'integer', 'between:-30,30']]);
        $key = $this->key($scene, $actor, $messageId);
        $operation = DB::table('damage_applications')->where($key)->first();
        abort_unless($operation && ! $operation->undone, 422, 'Aplique o dano antes do teste de concentração.');
        $resolution = json_decode($operation->resolution ?? '{}', true);
        abort_unless(isset($resolution['concentrationDc']), 422, 'Este dano não exige concentração.');
        if (! isset($resolution['concentrationSave'])) {
            $system = $actor->system;
            abort_unless(($system['concentration'] ?? null) == $resolution['concentrationBefore'], 409, 'A concentração já mudou; este teste não se aplica ao efeito atual.');
            try {
                $effectiveSystem = $this->effects->apply($system, $actor->activeEffects()->get());
                $options['effectFormula'] = $this->effects->formulaSuffix($actor->activeEffects()->get(), 'roll.save');
                $label = 'Concentração · '.$actor->name;
                $result = CombatRules::save($effectiveSystem, 'con', $resolution['concentrationDc'], $options, $dice, $scene->campaign->house_rules ?? [], $label,
                    fn ($formula, $rules, $mode) => $ledger->roll($scene, $request->user(), [
                        'requestId' => $messageId, 'step' => 'concentration:'.$actor->id, 'context' => 'concentration',
                        'actorId' => $actor->id, 'formula' => $formula, 'mode' => $mode, 'modePrepared' => true,
                        'label' => $label, 'houseRules' => $rules, 'visibility' => 'gm',
                    ]));
            } catch (InvalidArgumentException $e) {
                abort(422, $e->getMessage());
            }
            if (! $result['success']) {
                $system['concentration'] = null;
            }
            $resolution['concentrationSave'] = [...$result, 'userId' => $request->user()->id, 'createdAt' => now()->toIso8601String()];
            $resolution['concentrationAfter'] = $system['concentration'] ?? null;
            $actor->system = $system;
            $actor->save();
            DB::table('damage_applications')->where($key)->update(['resolution' => json_encode($resolution)]);
            broadcast(new SceneUpdated($scene, 'resolution'));
        }

        return response()->json(['actor' => $actor->toPayload(), 'save' => $resolution['concentrationSave']]);
    }

    public function undoAction(Request $request, Scene $scene, string $messageId)
    {
        return DB::transaction(fn () => $this->undoActionLocked($request, $scene, $messageId));
    }

    private function undoActionLocked(Request $request, Scene $scene, string $messageId)
    {
        $actor = $this->target($request, $scene);
        [, $record] = $this->source($request, $scene, $messageId);
        abort_unless($record && (int) $record->actor_id === $actor->id, 422);
        if (! $record->undone) {
            abort_if(DB::table('damage_applications')->where(['scene_id' => $scene->id, 'message_id' => $messageId, 'undone' => false])->exists(), 409, 'Desfaça o dano de todos os alvos primeiro.');
            abort_if(DB::table('action_healing_applications')->where(['scene_id' => $scene->id, 'message_id' => $messageId, 'undone' => false])->exists(), 409, 'Desfaça a cura confirmada de todos os alvos primeiro.');
            $system = $actor->system;
            $after = json_decode($record->resource_after, true);
            $before = json_decode($record->resource_before, true);
            $current = ['slots' => $system['spells']['slots'] ?? [], 'resources' => $system['resources'] ?? [], 'concentration' => $system['concentration'] ?? null];
            if (array_key_exists('inspiration', $after)) {
                $current['inspiration'] = $system['inspiration'] ?? false;
            }
            $chargeDocument = null;
            if (is_array($after['documentCharges'] ?? null)) {
                $chargeDocument = ActorDocument::query()->where('actor_id', $actor->id)->lockForUpdate()->find($after['documentCharges']['id'] ?? 0);
                abort_unless($chargeDocument && $chargeDocument->charges == ($after['documentCharges']['charges'] ?? null), 409, 'As cargas do item mudaram. Ajuste a ficha manualmente.');
                $current['documentCharges'] = ['id' => $chargeDocument->id, 'charges' => $chargeDocument->charges];
            }
            abort_unless($current == $after, 409, 'Os espaços, recursos ou a concentração mudaram. Ajuste a ficha manualmente.');
            $beforePools = ResourceAudit::snapshot($actor);
            $system['spells']['slots'] = $before['slots'];
            $system['resources'] = $before['resources'] ?? [];
            if (array_key_exists('inspiration', $before)) {
                $system['inspiration'] = $before['inspiration'];
            }
            $system['concentration'] = $before['concentration'];
            if ($chargeDocument && is_array($before['documentCharges']['charges'] ?? null)) {
                $chargeDocument->charges = $before['documentCharges']['charges'];
                $chargeDocument->save();
            }
            $actor->system = $system;
            $actor->save();
            ResourceAudit::record($actor, 'undo', $beforePools, ResourceAudit::snapshot($actor), $request->user()->id,
                null, null, null, 'action:'.$messageId);
            ActiveEffect::query()->where('metadata->actionMessageId', $messageId)->get()->each(function (ActiveEffect $effect) use ($request) {
                EffectAudit::record($effect, 'removed', $effect->toArray(), $request->user()->id, 'action-undo');
                $effect->delete();
            });
            DB::table('action_records')->where('id', $record->id)->update(['undone' => true, 'updated_at' => now()]);
            broadcast(new SceneUpdated($scene, 'resolution'));
        }

        return response()->json(['actor' => $actor->toPayload(), 'undone' => true]);
    }
}
