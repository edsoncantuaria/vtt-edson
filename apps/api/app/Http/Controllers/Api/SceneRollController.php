<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\RollLedger;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\ConditionRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class SceneRollController extends Controller
{
    use AuthorizesScene;

    public function index(Request $request, Scene $scene, RollLedger $ledger): JsonResponse
    {
        $this->requireMember($request, $scene);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $page = (int) ($data['page'] ?? 1);
        $user = $request->user();
        $gm = $scene->campaign->canManage($user);
        $query = DB::table('roll_records')->where('scene_id', $scene->id)
            ->where(function ($query) use ($user, $gm) {
                $query->where('visibility', 'public')
                    ->orWhere(function ($q) use ($gm, $user) {
                        $q->where('visibility', 'gm');
                        if (! $gm) {
                            $q->where('user_id', $user->id);
                        }
                    })
                    ->orWhere(fn ($q) => $q->where('visibility', 'private')->where(fn ($audience) => $audience
                        ->where('user_id', $user->id)->orWhere('recipient_user_id', $user->id)));
            });
        $records = $query->orderByDesc('created_at')->orderByDesc('id')
            ->offset(($page - 1) * 100)->limit(101)->get();
        $hasMore = $records->count() > 100;
        $records = $records->take(100);
        $readable = $gm ? null : $scene->campaign->actors()->where(fn ($query) => $query
            ->where('owner_user_id', $user->id)->orWhere('shared', true)
            ->orWhereIn('id', CampaignResourcePermission::query()->where('campaign_id', $scene->campaign_id)
                ->where('user_id', $user->id)->where('resource_type', 'actor')->pluck('resource_id')))->pluck('id')->all();

        return response()->json(['page' => $page, 'hasMore' => $hasMore, 'rolls' => $records->reverse()->values()->map(function ($record) use ($ledger, $readable) {
            $roll = $ledger->payload($record);
            if ($readable !== null && ! in_array($roll['actorId'], $readable, true)) {
                $roll['actorId'] = null;
            }

            return $roll;
        })]);
    }

    public function store(Request $request, Scene $scene, RollLedger $ledger): JsonResponse
    {
        $member = $this->requireParticipant($request, $scene);
        $data = $request->validate([
            'requestId' => ['required', 'uuid'], 'formula' => ['required', 'string', 'max:120'],
            'actorId' => ['nullable', 'integer', Rule::exists('actors', 'id')->where('campaign_id', $scene->campaign_id)],
            'context' => ['sometimes', 'in:custom,ability,skill,save,initiative,attack,damage,heal,concentration'],
            'ability' => ['sometimes', Rule::in(['str', 'dex', 'con', 'int', 'wis', 'cha'])],
            'mode' => ['sometimes', 'in:normal,advantage,disadvantage'],
            'modifier' => ['sometimes', 'integer', 'between:-100,100'],
            'extraDice' => ['sometimes', 'string', 'max:20'],
            'visibility' => ['sometimes', 'in:public,gm,private'],
            'recipientUserId' => ['required_if:visibility,private', 'nullable', 'integer', 'exists:users,id'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);
        $user = $request->user();
        $actor = null;
        if (isset($data['actorId'])) {
            $actor = Actor::where('campaign_id', $scene->campaign_id)->findOrFail($data['actorId']);
            abort_unless($member->isGm() || $actor->isOwnedBy($user)
                || CampaignResourcePermission::permits($scene->campaign, $user, 'actor', $actor->id, 'edit'), 403);
        }
        $context = $data['context'] ?? 'custom';
        if ($actor && in_array($context, ['attack', 'ability', 'skill', 'save'], true)) {
            $effective = app(ActiveEffectEngine::class)->effectiveSystem($actor);
            abort_if($context === 'attack' && ! ConditionRules::canAct($effective), 422, 'A condição atual impede este ataque.');
            $conditions = ConditionRules::mode($effective, $context,
                $data['mode'] ?? 'normal', $data['ability'] ?? null);
            $data['mode'] = $conditions['mode'];
        }
        if (($data['visibility'] ?? 'public') === 'private') {
            abort_unless($scene->memberFor(User::findOrFail($data['recipientUserId'])) !== null, 422, 'Destinatário não participa da cena.');
        } else {
            abort_if(isset($data['recipientUserId']), 422, 'Destinatário só é permitido para uma rolagem privada.');
        }
        try {
            $roll = $ledger->roll($scene, $user, [
                ...$data, 'context' => $data['context'] ?? 'custom',
            ]);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
        $visibility = $roll['visibility'];
        $message = null;
        if ($visibility === 'public') {
            $message = $ledger->chatMessage($roll, $user);
            if (! $roll['replayed']) {
                $state = $scene->state;
                $state['chat'][] = $message;
                $state['chat'] = array_slice($state['chat'], -200);
                $scene->state = $state;
                $scene->save();
                broadcast(new SceneUpdated($scene, 'chat'));
            }
        }

        return response()->json(['roll' => $roll, 'message' => $message, 'state' => $scene->stateFor($user)]);
    }
}
