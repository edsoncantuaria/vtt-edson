<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SceneController extends Controller
{
    use AuthorizesScene;

    public function show(Request $request, Scene $scene): JsonResponse
    {
        $member = $this->requireMember($request, $scene);

        return response()->json([
            'scene' => [
                'id' => $scene->id,
                'name' => $scene->name,
                'role' => $member->role,
                'state' => $scene->stateFor($request->user()),
                'backgroundUrl' => $scene->background_path
                    ? url('storage/'.$scene->background_path)
                    : ($scene->state['backgroundUrl'] ?? null),
            ],
        ]);
    }

    public function uploadBackground(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $request->validate([
            'background' => ['required', 'image', 'max:10240'],
        ]);

        $path = $request->file('background')->store('scenes/'.$scene->id, 'public');
        $scene->background_path = $path;
        $state = $scene->state;
        $state['backgroundUrl'] = url('storage/'.$path);
        $scene->state = $state;
        $scene->save();

        broadcast(new SceneUpdated($scene, 'background'));

        return response()->json([
            'backgroundUrl' => url('storage/'.$path),
            'state' => $scene->stateFor($request->user()),
        ]);
    }
}
