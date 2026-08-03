<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Room;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Support\SceneStateFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoomController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sceneName' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();

        $campaign = Campaign::create([
            'owner_id' => $user->id,
            'name' => $data['name'],
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
            'scene' => $this->scenePayload($scene, 'gm'),
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

        $scene = $room->campaign->scenes()->orderBy('id')->firstOrFail();
        $user = $request->user();

        $member = SceneMember::firstOrCreate(
            ['scene_id' => $scene->id, 'user_id' => $user->id],
            ['role' => $room->campaign->owner_id === $user->id ? 'gm' : 'player']
        );

        return response()->json([
            'campaign' => $room->campaign,
            'room' => $room,
            'scene' => $this->scenePayload($scene, $member->role),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scenePayload(Scene $scene, string $role): array
    {
        return [
            'id' => $scene->id,
            'name' => $scene->name,
            'role' => $role,
            'state' => $scene->state,
            'backgroundUrl' => $scene->background_path
                ? url('storage/'.$scene->background_path)
                : null,
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
