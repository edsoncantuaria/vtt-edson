<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Scene;
use App\Support\Dnd\CombatRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DamageController extends Controller
{
    use AuthorizesScene;

    private function target(Request $request, Scene $scene): Actor
    {
        $member = $this->requireMember($request, $scene);
        $data = $request->validate(['actorId' => ['required', 'integer']]);
        $query = Actor::where('campaign_id', $scene->campaign_id);
        if (! $request->isMethodSafe()) {
            $query->lockForUpdate();
        }
        $actor = $query->findOrFail($data['actorId']);
        abort_unless($member->isGm() || $actor->isOwnedBy($request->user()), 403);

        return $actor;
    }

    private function source(Scene $scene, string $messageId): array
    {
        $record = DB::table('action_records')->where(['scene_id' => $scene->id, 'message_id' => $messageId])->first();
        $message = $record ? json_decode($record->message, true) : collect($scene->state['chat'] ?? [])->firstWhere('id', $messageId);
        abort_unless($message, 404, 'Ação não encontrada.');

        return [$message, $record];
    }

    private function key(Scene $scene, Actor $actor, string $messageId): array
    {
        return ['scene_id' => $scene->id, 'actor_id' => $actor->id, 'message_id' => $messageId];
    }

    public function history(Request $request, Scene $scene)
    {
        $this->requireMember($request, $scene);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $records = DB::table('action_records')->where('scene_id', $scene->id)->orderByDesc('id')->paginate(30);

        return response()->json(['messages' => collect($records->items())->map(fn ($record) => [...json_decode($record->message, true), 'undone' => (bool) $record->undone]), 'lastPage' => $records->lastPage()]);
    }

    public function show(Request $request, Scene $scene, string $messageId)
    {
        $actor = $this->target($request, $scene);
        [$message, $record] = $this->source($scene, $messageId);
        $save = $record ? (json_decode($record->saves ?? '{}', true)[$actor->id] ?? null) : null;
        $operation = DB::table('damage_applications')->where($this->key($scene, $actor, $messageId))->first();
        $hasDamage = collect($message['rolls'] ?? [])->contains('kind', 'damage');

        return response()->json([
            'preview' => $hasDamage ? CombatRules::damage($message, $actor->system, $save) : null,
            'save' => $save,
            'application' => $operation ? ['undone' => (bool) $operation->undone, 'before' => json_decode($operation->before, true), 'after' => json_decode($operation->after, true), 'resolution' => json_decode($operation->resolution ?? '{}', true)] : null,
            'actionUndone' => (bool) ($record->undone ?? false),
        ]);
    }

    public function save(Request $request, Scene $scene, string $messageId, DiceRoller $dice)
    {
        return DB::transaction(fn () => $this->saveLocked($request, $scene, $messageId, $dice));
    }

    private function saveLocked(Request $request, Scene $scene, string $messageId, DiceRoller $dice)
    {
        $actor = $this->target($request, $scene);
        $options = $request->validate([
            'mode' => ['sometimes', 'in:normal,advantage,disadvantage'],
            'bonus' => ['sometimes', 'integer', 'between:-30,30'],
            'decision' => ['sometimes', 'in:roll,success,failure'],
            'reason' => ['required_if:decision,success,failure', 'string', 'max:240'],
        ]);
        [$message, $record] = $this->source($scene, $messageId);
        abort_unless($record && isset($message['save']) && ! $record->undone, 422, 'Esta ação não tem salvaguarda pendente.');
        $saves = json_decode($record->saves ?? '{}', true);
        $previous = $saves[$actor->id] ?? null;
        if ($previous && ($options['decision'] ?? 'roll') === 'roll') {
            return response()->json(['save' => $previous]);
        }
        if ($previous) {
            abort_unless($scene->campaign->roleFor($request->user()) === 'gm', 403, 'Apenas o mestre pode substituir uma salvaguarda já resolvida.');
        }
        abort_if(DB::table('damage_applications')->where($this->key($scene, $actor, $messageId))->exists(), 409, 'O dano já foi decidido.');
        $decision = $options['decision'] ?? 'roll';
        if ($decision !== 'roll') {
            $gm = $scene->campaign->roleFor($request->user()) === 'gm';
            abort_unless($gm || ($decision === 'failure' && $scene->campaign->ruleset === '5e-2024'), 403, 'Esta decisão requer o mestre nesta edição.');
            $result = ['ability' => $message['save']['ability'], 'dc' => $message['save']['dc'], 'success' => $decision === 'success', 'reason' => $options['reason'], 'manual' => true];
        } else {
            try {
                $result = CombatRules::save($actor->system, $message['save']['ability'], $message['save']['dc'], $options, $dice, $scene->campaign->house_rules ?? [], 'Salvaguarda de '.$message['save']['ability'].' · '.$message['label']);
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
            'undo' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'string', 'max:240'],
        ]);
        $key = $this->key($scene, $actor, $messageId);
        $operation = DB::table('damage_applications')->where($key)->first();
        $system = $actor->system;
        if ($data['undo'] ?? false) {
            abort_unless($operation !== null, 404);
            if (! $operation->undone) {
                $resolution = json_decode($operation->resolution ?? '{}', true);
                abort_unless(($system['hp'] ?? []) == json_decode($operation->after, true), 409, 'Os PV mudaram depois deste dano. Ajuste a ficha manualmente.');
                if (array_key_exists('concentrationAfter', $resolution)) {
                    abort_unless(($system['concentration'] ?? null) == $resolution['concentrationAfter'], 409, 'A concentração mudou depois deste dano.');
                    $system['concentration'] = $resolution['concentrationBefore'];
                }
                $system['hp'] = json_decode($operation->before, true);
                $resolution['undoneBy'] = $request->user()->id;
                $resolution['undoneAt'] = now()->toIso8601String();
                DB::table('damage_applications')->where($key)->update(['undone' => true, 'resolution' => json_encode($resolution)]);
            }
        } elseif (! $operation) {
            [$message, $record] = $this->source($scene, $messageId);
            abort_if($record?->undone, 409, 'Esta ação foi desfeita.');
            $save = $record ? (json_decode($record->saves ?? '{}', true)[$actor->id] ?? null) : null;
            $resolution = CombatRules::damage($message, $system, $save, isset($data['factor']) ? (float) $data['factor'] : null);
            abort_if($resolution['pendingSave'], 422, 'Resolva a salvaguarda ou escolha uma decisão manual.');
            $before = $system['hp'] ?? null;
            abort_unless(is_array($before) && isset($before['value'], $before['max']) && is_numeric($before['value']) && is_numeric($before['max']), 422, 'Revise os PV da ficha.');
            $damage = $resolution['damage'];
            $absorbed = min(max(0, $before['temp'] ?? 0), $damage);
            $system['hp']['temp'] = max(0, ($before['temp'] ?? 0) - $absorbed);
            $system['hp']['value'] = max(0, $before['value'] - ($damage - $absorbed));
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
            $resolution['save'] = $save;
            DB::table('damage_applications')->insert([...$key, 'before' => json_encode($before), 'after' => json_encode($system['hp']), 'resolution' => json_encode($resolution), 'undone' => false]);
        }
        $actor->system = $system;
        $actor->save();
        broadcast(new SceneUpdated($scene, 'resolution'));

        return response()->json(['actor' => $actor->toPayload(), 'undone' => (bool) ($data['undo'] ?? $operation->undone ?? false)]);
    }

    public function concentration(Request $request, Scene $scene, string $messageId, DiceRoller $dice)
    {
        return DB::transaction(fn () => $this->concentrationLocked($request, $scene, $messageId, $dice));
    }

    private function concentrationLocked(Request $request, Scene $scene, string $messageId, DiceRoller $dice)
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
                $result = CombatRules::save($system, 'con', $resolution['concentrationDc'], $options, $dice, $scene->campaign->house_rules ?? [], 'Concentração · '.$actor->name);
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
        [, $record] = $this->source($scene, $messageId);
        abort_unless($record && (int) $record->actor_id === $actor->id, 422);
        if (! $record->undone) {
            abort_if(DB::table('damage_applications')->where(['scene_id' => $scene->id, 'message_id' => $messageId, 'undone' => false])->exists(), 409, 'Desfaça o dano de todos os alvos primeiro.');
            $system = $actor->system;
            $current = ['slots' => $system['spells']['slots'] ?? [], 'resources' => $system['resources'] ?? [], 'concentration' => $system['concentration'] ?? null];
            abort_unless($current == json_decode($record->resource_after, true), 409, 'Os espaços ou a concentração mudaram. Ajuste a ficha manualmente.');
            $before = json_decode($record->resource_before, true);
            $system['spells']['slots'] = $before['slots'];
            $system['resources'] = $before['resources'] ?? [];
            $system['concentration'] = $before['concentration'];
            $actor->system = $system;
            $actor->save();
            DB::table('action_records')->where('id', $record->id)->update(['undone' => true, 'updated_at' => now()]);
            broadcast(new SceneUpdated($scene, 'resolution'));
        }

        return response()->json(['actor' => $actor->toPayload(), 'undone' => true]);
    }
}
