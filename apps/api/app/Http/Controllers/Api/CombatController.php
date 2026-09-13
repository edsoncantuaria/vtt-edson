<?php

namespace App\Http\Controllers\Api;

use App\Events\CombatUpdated;
use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Combat;
use App\Models\CombatParticipant;
use App\Models\Scene;
use App\Support\Dnd\AbilityScore;
use App\Support\Dnd\ActiveEffectEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CombatController extends Controller
{
    use AuthorizesScene;

    public function __construct(private readonly ActiveEffectEngine $effects) {}

    public function show(Request $request, Scene $scene): JsonResponse
    {
        $this->requireMember($request, $scene);

        return response()->json(['combat' => $this->activeCombat($scene)?->load('participants')?->payloadFor($request->user())]);
    }

    public function start(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $this->activeCombat($scene)?->delete();

        $combat = Combat::create(['scene_id' => $scene->id, 'round' => 1, 'turn' => 0, 'is_active' => true]);

        return $this->respond($scene, $combat);
    }

    public function end(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $this->activeCombat($scene)?->delete();

        return $this->respond($scene, null);
    }

    public function addCombatant(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $combat = $this->activeCombat($scene);
        if (! $combat) {
            abort(404, 'Nenhum combate ativo nesta cena.');
        }

        $data = $request->validate([
            'actorId' => ['nullable', 'integer', Rule::exists('actors', 'id')->where('campaign_id', $scene->campaign_id)],
            'tokenId' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        if (isset($data['tokenId'])) {
            $token = collect($scene->state['tokens'])->firstWhere('id', $data['tokenId']);
            abort_unless($token, 422, 'Token não pertence a esta cena.');
            if (isset($data['actorId'])) {
                abort_unless(($token['actorId'] ?? null) === $data['actorId'], 422, 'Token e ficha não correspondem.');
            } else {
                $data['actorId'] = $token['actorId'] ?? null;
            }
        }

        $actor = isset($data['actorId']) ? Actor::find($data['actorId']) : null;

        CombatParticipant::create([
            'combat_id' => $combat->id,
            'actor_id' => $actor?->id,
            'token_id' => $data['tokenId'] ?? null,
            'name' => $data['name'] ?? $actor->name ?? 'Combatente',
            'img_path' => $actor?->img_path,
            'sort' => $combat->participants()->count(),
        ]);

        return $this->respond($scene, $combat);
    }

    public function removeCombatant(Request $request, Scene $scene, CombatParticipant $participant): JsonResponse
    {
        $this->requireGm($request, $scene);
        $combat = $this->activeCombat($scene);
        if (! $combat || $participant->combat_id !== $combat->id) {
            abort(404, 'Combatente não encontrado neste combate.');
        }
        $participant->delete();

        return $this->respond($scene, $combat);
    }

    public function rollInitiative(Request $request, Scene $scene, DiceRoller $dice): JsonResponse
    {
        $this->requireGm($request, $scene);
        $combat = $this->activeCombat($scene);
        if (! $combat) {
            abort(404, 'Nenhum combate ativo nesta cena.');
        }

        foreach ($combat->participants as $participant) {
            $dexMod = 0;
            if ($participant->actor) {
                $effectiveSystem = $this->effects->effectiveSystem($participant->actor);
                $score = (int) ($effectiveSystem['abilities']['dex']['score'] ?? 10);
                $dexMod = AbilityScore::modifier($score);
                $dexMod += (int) round($this->effects->rollModifier($participant->actor->activeEffects()->get(), 'roll.initiative'));
            }
            $formula = $dexMod === 0 ? 'd20' : sprintf('d20%+d', $dexMod);
            $result = $dice->roll($formula);
            $participant->initiative = $result['total'];
            $participant->save();
        }

        // Maior iniciativa primeiro; empate mantém a ordem atual.
        $ordered = $combat->participants()->orderByDesc('initiative')->get();
        foreach ($ordered->values() as $index => $participant) {
            $participant->sort = $index;
            $participant->save();
        }
        $combat->turn = 0;
        $combat->round = 1;
        $combat->save();

        return $this->respond($scene, $combat);
    }

    public function next(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $combat = $this->activeCombat($scene);
        if (! $combat) {
            abort(404, 'Nenhum combate ativo nesta cena.');
        }

        $count = $combat->participants()->count();
        if ($count > 0) {
            $nextTurn = $combat->turn + 1;
            if ($nextTurn >= $count) {
                $nextTurn = 0;
                $combat->round++;
                $this->effects->advanceRound($combat);
            }
            $combat->turn = $nextTurn;
            $combat->save();
        }

        return $this->respond($scene, $combat);
    }

    public function prev(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $combat = $this->activeCombat($scene);
        if (! $combat) {
            abort(404, 'Nenhum combate ativo nesta cena.');
        }

        $count = $combat->participants()->count();
        if ($count > 0) {
            $prevTurn = $combat->turn - 1;
            if ($prevTurn < 0) {
                $prevTurn = $count - 1;
                $combat->round = max(1, $combat->round - 1);
            }
            $combat->turn = $prevTurn;
            $combat->save();
        }

        return $this->respond($scene, $combat);
    }

    private function activeCombat(Scene $scene): ?Combat
    {
        return Combat::where('scene_id', $scene->id)->where('is_active', true)->latest('id')->first();
    }

    private function respond(Scene $scene, ?Combat $combat): JsonResponse
    {
        $combat?->load('participants');
        broadcast(new CombatUpdated($scene->id, $combat));

        return response()->json(['combat' => $combat?->toPayload()]);
    }
}
