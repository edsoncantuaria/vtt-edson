<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteSceneActionRequest;
use App\Models\Actor;
use App\Models\Scene;
use App\Support\Dnd\CombatRules;
use App\Support\Dnd\HouseRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SceneActionController extends Controller
{
    use AuthorizesScene;

    public function action(ExecuteSceneActionRequest $request, Scene $scene, DiceRoller $dice): JsonResponse
    {
        $member = $this->requireMember($request, $scene);
        $data = $request->validated();
        $actor = Actor::query()->lockForUpdate()->findOrFail($data['actorId']);
        if ((int) $actor->campaign_id !== (int) $scene->campaign_id) {
            abort(422, 'Essa ficha não pertence à campanha desta cena.');
        }
        if (! $member->isGm() && ! $actor->isOwnedBy($request->user())) {
            abort(403, 'Você só pode usar ações da sua própria ficha.');
        }
        $requestId = $data['requestId'] ?? (string) Str::uuid();
        $existing = DB::table('action_records')->where(['scene_id' => $scene->id, 'user_id' => $request->user()->id, 'request_id' => $requestId])->first();
        if ($existing) {
            abort_unless((int) $existing->actor_id === $actor->id, 409);

            return response()->json(['message' => json_decode($existing->message, true), 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user())]);
        }
        $action = collect($actor->system['actions'] ?? [])->firstWhere('id', $data['actionId']);
        if (! $action) {
            abort(404, 'Ação não encontrada na ficha.');
        }
        CombatRules::validateAction($action);
        $system = $actor->system;
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
        $rolls = [];
        $appliedRules = [];
        try {
            foreach (['attackFormula' => 'attack', 'damageFormula' => 'damage'] as $field => $kind) {
                $formula = trim((string) ($action[$field] ?? ''));
                if ($formula === '') {
                    continue;
                }
                if ($kind === 'attack' && ($data['mode'] ?? 'normal') !== 'normal') {
                    abort_unless(preg_match('/^(?:1)?d20([+-]\d+)?$/i', $formula, $match), 422, 'Para escolher vantagem, use uma fórmula de ataque 1d20±K.');
                    $formula = ($data['mode'] === 'advantage' ? '2d20kh1' : '2d20kl1').($match[1] ?? '');
                }
                $adjusted = HouseRules::apply($formula, $action['name'].' · '.($kind === 'attack' ? 'Ataque' : 'Dano'), $scene->campaign->house_rules ?? []);
                $formula = $adjusted['formula'];
                if ($kind === 'damage' && ($rolls[0]['kind'] ?? null) === 'attack' && $rolls[0]['critical']) {
                    $formula = CombatRules::criticalFormula($formula);
                }
                $rolls[] = ['kind' => $kind, ...$dice->roll($formula)];
                $appliedRules = array_merge($appliedRules, $adjusted['rules']);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        abort_unless(count($rolls) || isset($action['saveAbility']), 422, 'Defina ataque, dano ou salvaguarda antes de usar esta ação.');
        if ($slotLevel !== null) {
            $system['spells']['slots'][$slotLevel]['used'] = ($slot['used'] ?? 0) + 1;
        }
        if ($genericResource !== null) {
            $resourceIndex = collect($system['resources'])->search(fn ($resource) => ($resource['id'] ?? null) === $action['resourceId']);
            $system['resources'][$resourceIndex]['used'] = ($genericResource['used'] ?? 0) + (int) ($action['resourceCost'] ?? 1);
        }
        if ($action['concentration'] ?? false) {
            $system['concentration'] = ['id' => (string) Str::uuid(), 'name' => $action['name']];
        }
        $actor->system = $system;
        $actor->save();
        $system = $actor->system;
        $result = $rolls[0] ?? ['formula' => '', 'total' => 0, 'detail' => 'Aguardando salvaguarda dos alvos', 'critical' => false, 'fumble' => false];
        $user = $request->user();
        $message = [
            'id' => (string) Str::uuid(),
            'userId' => $user->id,
            'userName' => $user->name,
            'type' => 'action',
            'actionKind' => $action['kind'] ?? 'attack',
            'sourceActorId' => $actor->id,
            'damageType' => $action['damageType'] ?? null,
            'save' => isset($action['saveAbility']) ? ['ability' => $action['saveAbility'], 'dc' => CombatRules::saveDc($system, $action), 'effect' => $action['saveEffect']] : null,
            'houseRules' => array_values(array_unique($appliedRules)),
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
            'createdAt' => now()->toIso8601String(),
        ];
        DB::table('action_records')->insert([
            'scene_id' => $scene->id, 'actor_id' => $actor->id, 'user_id' => $user->id,
            'request_id' => $requestId, 'message_id' => $message['id'], 'message' => json_encode($message),
            'resource_before' => json_encode($resourceBefore),
            'resource_after' => json_encode(['slots' => $system['spells']['slots'] ?? [], 'resources' => $system['resources'] ?? [], 'concentration' => $system['concentration'] ?? null]),
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
