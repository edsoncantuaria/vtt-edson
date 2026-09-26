<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Models\Scene;
use App\Support\Dnd\DeathSaveRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Scene lock is acquired by SerializeSceneWrites before locking the actor. */
final class ActorDeathSaveController extends Controller
{
    use AuthorizesScene;

    public function store(Request $request, Scene $scene, Actor $actor, DiceRoller $dice): JsonResponse
    {
        $member = $this->requireParticipant($request, $scene);
        abort_unless($actor->campaign_id === $scene->campaign_id, 404);
        abort_unless($member->isGm() || $actor->isOwnedBy($request->user())
            || CampaignResourcePermission::permits($scene->campaign, $request->user(), 'actor', $actor->id, 'edit'), 403);
        $data = $request->validate(['requestId' => ['required', 'uuid']]);
        $actor = Actor::whereKey($actor->id)->lockForUpdate()->firstOrFail();

        $previous = DB::table('actor_death_save_rolls')->where('actor_id', $actor->id)
            ->where('request_id', $data['requestId'])->first();
        if ($previous) {
            abort_unless($previous->scene_id === $scene->id, 409, 'Essa chave de rolagem já foi utilizada em outra cena.');

            return response()->json(['message' => json_decode($previous->message, true), 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user()), 'alreadyRolled' => true]);
        }

        $system = $actor->system;
        abort_unless($actor->type === 'character' && (int) ($system['hp']['value'] ?? -1) === 0, 422, 'A salvaguarda contra morte exige um personagem com 0 PV.');
        abort_if(($system['deathSaves']['success'] ?? 0) >= 3 || ($system['deathSaves']['failure'] ?? 0) >= 3
            || array_intersect($system['conditions'] ?? [], ['estabilizado', 'morto']), 422, 'O desfecho das salvaguardas já foi resolvido.');

        $rolled = $dice->roll('d20');
        $resolved = DeathSaveRules::resolve($system, $rolled['natural']);
        $actor->system = $resolved['system'];
        $actor->save();
        $message = [
            'id' => (string) Str::uuid(), 'userId' => $request->user()->id,
            'userName' => $request->user()->name, 'createdAt' => now()->toIso8601String(),
            'type' => 'roll', 'sourceActorId' => $actor->id,
            'label' => Str::limit($actor->name.' · Salvaguarda contra morte', 80, ''),
            'formula' => $rolled['formula'], 'total' => $rolled['total'], 'detail' => $rolled['detail'],
            'critical' => $rolled['critical'], 'fumble' => $rolled['fumble'],
            'text' => $resolved['outcome'],
        ];
        DB::table('actor_death_save_rolls')->insert([
            'scene_id' => $scene->id, 'actor_id' => $actor->id, 'user_id' => $request->user()->id,
            'request_id' => $data['requestId'], 'message' => json_encode($message, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $state = $scene->state;
        $state['chat'][] = $message;
        $state['chat'] = array_slice($state['chat'], -200);
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'chat'));

        return response()->json(['message' => $message, 'actor' => $actor->toPayload(), 'state' => $scene->stateFor($request->user()), 'alreadyRolled' => false]);
    }
}
