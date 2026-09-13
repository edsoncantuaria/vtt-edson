<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Room;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\SceneStateFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $rooms = Room::query()
            ->whereHas('campaign.scenes.members', fn ($query) => $query->where('user_id', $userId))
            ->with(['campaign.owner', 'campaign.scenes' => fn ($query) => $query
                ->whereHas('members', fn ($members) => $members->where('user_id', $userId))
                ->with(['members' => fn ($members) => $members->where('user_id', $userId)])
                ->withCount('members')->orderBy('id')])
            ->latest('updated_at')->get()->map(function (Room $room) {
                $scene = $room->campaign->scenes->first(fn ($candidate) => $candidate->published || $candidate->members->first()?->isGm());
                if (! $scene) {
                    return null;
                }

                return [
                    'code' => $room->code,
                    'campaignId' => $room->campaign_id,
                    'name' => $room->campaign->name,
                    'ruleset' => $room->campaign->ruleset,
                    'gmName' => $room->campaign->owner->name,
                    'sceneId' => $scene->id,
                    'sceneName' => $scene->name,
                    'role' => $scene->members->first()->role,
                    'memberCount' => $scene->members_count,
                    'backgroundUrl' => $scene->background_path ? url('storage/'.$scene->background_path) : ($scene->state['backgroundUrl'] ?? null),
                    'updatedAt' => $scene->updated_at->toIso8601String(),
                ];
            });

        return response()->json(['rooms' => $rooms->filter()->values()]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sceneName' => ['nullable', 'string', 'max:120'],
            'ruleset' => ['sometimes', 'in:5e-2014,5e-2024'],
            'library' => ['sometimes', 'in:core,all'],
        ]);

        $user = $request->user();

        $campaign = Campaign::create([
            'owner_id' => $user->id,
            'name' => $data['name'],
            'ruleset' => $data['ruleset'] ?? '5e-2014',
            'catalog_sources' => ($data['library'] ?? 'core') === 'all' ? null : (($data['ruleset'] ?? '5e-2014') === '5e-2024' ? ['XPHB', 'XDMG', 'XMM'] : ['PHB', 'DMG', 'MM']),
        ]);

        $code = $this->uniqueCode();
        $room = Room::create([
            'campaign_id' => $campaign->id,
            'code' => $code,
        ]);

        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => $data['sceneName'] ?? 'Cena 1',
            'state' => SceneStateFactory::empty(),
        ]);

        SceneMember::create([
            'scene_id' => $scene->id,
            'user_id' => $user->id,
            'role' => 'gm',
        ]);

        return response()->json([
            'campaign' => $campaign,
            'room' => $room,
            'scene' => $this->scenePayload($scene, 'gm', $user),
        ], 201);
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
        ]);

        $room = Room::query()
            ->where('code', Str::upper($data['code']))
            ->with('campaign.scenes')
            ->firstOrFail();

        $user = $request->user();
        $scenes = $room->campaign->scenes()->orderBy('id')->get();
        $role = $room->campaign->owner_id === $user->id ? 'gm' : 'player';
        foreach ($scenes as $candidate) {
            SceneMember::firstOrCreate(['scene_id' => $candidate->id, 'user_id' => $user->id], ['role' => $role]);
        }
        $scene = $scenes->first(fn ($candidate) => $role === 'gm' || $candidate->published) ?? abort(409, 'Aguarde o mestre publicar uma cena.');

        return response()->json([
            'campaign' => $room->campaign,
            'room' => $room,
            'scene' => $this->scenePayload($scene, $role, $user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scenePayload(Scene $scene, string $role, User $user): array
    {
        return [
            'id' => $scene->id,
            'name' => $scene->name,
            'role' => $role,
            'state' => $scene->stateFor($user),
            'backgroundUrl' => $scene->background_path
                ? url('storage/'.$scene->background_path)
                : ($scene->state['backgroundUrl'] ?? null),
        ];
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (Room::where('code', $code)->exists());

        return $code;
    }
}
