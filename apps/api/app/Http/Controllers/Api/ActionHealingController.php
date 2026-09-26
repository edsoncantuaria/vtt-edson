<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Scene;
use App\Support\Dnd\VitalityCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Confirmation is a separate, auditable step: rolling healing never edits the target. */
final class ActionHealingController extends Controller
{
    use AuthorizesScene;

    public function show(Request $request, Scene $scene, string $messageId)
    {
        $this->requireMember($request, $scene);
        [$actor, $record, $roll, $operation] = $this->lookup($request, $scene, $messageId, false);

        return response()->json($this->payload($actor, $record, $roll, $operation));
    }

    public function store(Request $request, Scene $scene, string $messageId)
    {
        $this->requireParticipant($request, $scene);
        $data = $request->validate([
            'actorId' => ['required', 'integer'],
            'confirmed' => ['exclude_if:undo,true', 'required', 'accepted'],
            'undo' => ['sometimes', 'boolean'],
            'amountOverride' => ['sometimes', 'integer', 'between:0,100000'],
            'reason' => ['required_with:amountOverride', 'string', 'max:240'],
        ]);

        return DB::transaction(function () use ($request, $scene, $messageId, $data) {
            [$actor, $record, $roll, $operation] = $this->lookup($request, $scene, $messageId, true);
            $manual = array_key_exists('amountOverride', $data);
            abort_if($manual && ! $scene->campaign->canManage($request->user()), 403, 'Somente o mestre pode ajustar a cura.');
            abort_if($manual && ($data['undo'] ?? false), 422, 'Não combine ajuste de cura e desfazer.');
            abort_if($manual && trim($data['reason'] ?? '') === '', 422, 'Informe o motivo do ajuste da cura.');
            $system = $actor->system;
            $current = $system['hp'];
            if ($data['undo'] ?? false) {
                abort_unless($operation && ! $operation->undone, 422, 'Esta cura não está aplicada.');
                $after = json_decode($operation->after, true, flags: JSON_THROW_ON_ERROR);
                abort_unless($after === $current, 409, 'Os PV mudaram; a cura não pode ser revertida automaticamente.');
                $resolution = json_decode($operation->resolution ?? '{}', true);
                if (! empty($resolution['statusAfter'])) {
                    abort_unless(($system['deathSaves'] ?? null) === $resolution['statusAfter']['deathSaves']
                        && ($system['conditions'] ?? []) === $resolution['statusAfter']['conditions'],
                        409, 'Salvaguardas ou condições mudaram; não é seguro desfazer a cura.');
                    $system['deathSaves'] = $resolution['statusBefore']['deathSaves'];
                    $system['conditions'] = $resolution['statusBefore']['conditions'];
                }
                $system['hp'] = json_decode($operation->before, true, flags: JSON_THROW_ON_ERROR);
                DB::table('action_healing_applications')->where('id', $operation->id)->update(['undone' => true, 'updated_at' => now()]);
            } elseif (! $operation) {
                abort_if($record->undone, 409, 'A ação foi desfeita.');
                $before = $current;
                $resolution = VitalityCalculator::healSystem($system, max(0, (int) $roll['total']), $data['amountOverride'] ?? null, $actor->type === 'character');
                $system = $resolution['system'];
                unset($resolution['system']);
                $resolution['rollId'] = $roll['id'];
                $resolution['reason'] = $data['reason'] ?? null;
                $resolution['userId'] = $request->user()->id;
                $resolution['createdAt'] = now()->toIso8601String();
                DB::table('action_healing_applications')->insert([
                    'scene_id' => $scene->id, 'actor_id' => $actor->id, 'message_id' => $messageId,
                    'confirmed_by' => $request->user()->id, 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after' => json_encode($system['hp'], JSON_THROW_ON_ERROR), 'amount' => $resolution['restored'],
                    'resolution' => json_encode($resolution, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                abort_if($record->undone || $operation->undone, 409, 'A cura foi desfeita. Uma nova execução requer uma nova ação.');
                if ($manual) {
                    $prior = json_decode($operation->resolution ?? '{}', true);
                    abort_unless(($prior['requested'] ?? (int) $operation->amount) === (int) $data['amountOverride']
                        && ($prior['reason'] ?? null) === ($data['reason'] ?? null), 409, 'Esta cura já foi confirmada com outro ajuste.');
                }
            }
            if (! $operation) {
                $actor->system = $system;
                $actor->save();
            } elseif ($data['undo'] ?? false) {
                $actor->system = $system;
                $actor->save();
            }
            broadcast(new SceneUpdated($scene, 'resolution'));

            return response()->json(['actor' => $actor->toPayload(), ...$this->payload($actor, $record, $roll, DB::table('action_healing_applications')
                ->where(['scene_id' => $scene->id, 'actor_id' => $actor->id, 'message_id' => $messageId])->first())]);
        });
    }

    private function lookup(Request $request, Scene $scene, string $messageId, bool $lock): array
    {
        $actorId = $request->validate(['actorId' => ['required', 'integer']])['actorId'];
        $actor = Actor::where('campaign_id', $scene->campaign_id);
        if ($lock) {
            $actor->lockForUpdate();
        }
        $actor = $actor->findOrFail($actorId);
        abort_unless($scene->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user()), 403);
        $record = DB::table('action_records')->where(['scene_id' => $scene->id, 'message_id' => $messageId])->first();
        abort_unless($record, 404);
        $message = json_decode($record->message, true, flags: JSON_THROW_ON_ERROR);
        abort_if(($message['visibility'] ?? 'public') === 'gm' && ! $scene->campaign->canManage($request->user()), 404);
        $roll = collect($message['rolls'] ?? [])->firstWhere('kind', 'heal');
        abort_unless($roll, 422, 'Esta ação não inclui uma cura.');
        $targets = $message['targetActorIds'] ?? [];
        abort_unless($targets ? in_array($actor->id, $targets, true) : $actor->id === (int) $record->actor_id, 422, 'A ficha não foi selecionada como alvo desta cura.');
        $operation = DB::table('action_healing_applications')->where(['scene_id' => $scene->id, 'actor_id' => $actor->id, 'message_id' => $messageId])->first();

        return [$actor, $record, $roll, $operation];
    }

    private function payload(Actor $actor, object $record, array $roll, ?object $operation): array
    {
        $hp = $actor->system['hp'];
        $preview = VitalityCalculator::heal($hp, max(0, (int) $roll['total']));
        $dead = $actor->type === 'character' && in_array('morto', array_map('mb_strtolower', $actor->system['conditions'] ?? []), true);

        return [
            'preview' => ['rollId' => $roll['id'], 'rolled' => $preview['rolled'], 'restored' => $preview['restored'],
                'excess' => $preview['excess'], 'steps' => $preview['steps'], 'sources' => $preview['sources'],
                'current' => $hp['value'], 'max' => $hp['max'],
                'blockedReason' => $dead ? 'Cura comum não ressuscita personagem morto.' : null],
            'application' => $operation ? [
                'operationId' => $operation->id, 'amount' => $operation->amount, 'confirmedBy' => $operation->confirmed_by,
                'before' => json_decode($operation->before, true), 'after' => json_decode($operation->after, true),
                'resolution' => json_decode($operation->resolution ?? '{}', true),
                'undone' => (bool) $operation->undone,
            ] : null,
            'actionUndone' => (bool) $record->undone,
        ];
    }
}
