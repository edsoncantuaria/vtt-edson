<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertSceneTokenRequest;
use App\Models\Actor;
use App\Models\Scene;
use App\Support\Geometry\SegmentGeometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SceneTokenController extends Controller
{
    use AuthorizesScene;

    public function upsertToken(UpsertSceneTokenRequest $request, Scene $scene): JsonResponse
    {
        $member = $this->requireMember($request, $scene);
        $data = $request->validated();

        $state = $scene->state;
        $id = $data['id'] ?? (string) Str::uuid();
        $existingIndex = collect($state['tokens'])->search(fn ($t) => $t['id'] === $id);

        if ($existingIndex === false) {
            $this->requireGm($request, $scene);
            $state['tokens'][] = [
                'id' => $id,
                'x' => (float) $data['x'],
                'y' => (float) $data['y'],
                'name' => $data['name'] ?? 'Token',
                'ownerUserId' => $data['ownerUserId'] ?? $request->user()->id,
                'size' => (float) ($data['size'] ?? 1),
                ...(isset($data['appearance']) ? ['appearance' => $data['appearance']] : []),
                'actorId' => $data['actorId'] ?? null,
                'hidden' => $data['hidden'] ?? false,
                'stealthDc' => $data['stealthDc'] ?? null,
            ];
        } else {
            $token = $state['tokens'][$existingIndex];
            if (! $member->isGm() && (($token['hidden'] ?? false) || (int) ($token['ownerUserId'] ?? 0) !== (int) $request->user()->id)) {
                abort(403, 'Você só pode mover seus próprios tokens.');
            }
            $destination = ['x' => (float) $data['x'], 'y' => (float) $data['y']];
            if (SegmentGeometry::movementBlocked($token, $destination, $state)) {
                abort(422, 'O movimento atravessa uma parede ou porta fechada.');
            }
            $token['x'] = (float) $data['x'];
            $token['y'] = (float) $data['y'];
            if ($member->isGm() && isset($data['name'])) {
                $token['name'] = $data['name'];
            }
            if ($member->isGm() && array_key_exists('ownerUserId', $data)) {
                $token['ownerUserId'] = $data['ownerUserId'];
            }
            if ($member->isGm() && array_key_exists('actorId', $data)) {
                $token['actorId'] = $data['actorId'];
            }
            if ($member->isGm() && isset($data['size'])) {
                $token['size'] = (float) $data['size'];
            }
            if ($member->isGm() && isset($data['appearance'])) {
                $token['appearance'] = $data['appearance'];
            }
            if ($member->isGm() && array_key_exists('hidden', $data)) {
                $token['hidden'] = $data['hidden'];
            }
            if ($member->isGm() && array_key_exists('stealthDc', $data)) {
                $token['stealthDc'] = $data['stealthDc'];
            }
            $state['tokens'][$existingIndex] = $token;
        }

        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'token'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    public function prepareEncounter(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'actorId' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'x' => ['required', 'numeric'], 'y' => ['required', 'numeric'], 'requestId' => ['required', 'uuid'],
        ]);
        $template = Actor::where('campaign_id', $scene->campaign_id)->findOrFail($data['actorId']);
        abort_if($template->type === 'character', 422, 'Use uma criatura ou NPC como modelo do encontro.');
        $state = $scene->state;
        $prefix = $data['requestId'].':';
        if (! collect($state['tokens'])->contains(fn ($token) => str_starts_with($token['id'], $prefix))) {
            for ($i = 0; $i < $data['quantity']; $i++) {
                $actor = $template->replicate();
                $actor->name = mb_substr($template->name, 0, 110).' '.($i + 1);
                $actor->shared = false;
                $actor->owner_user_id = $request->user()->id;
                $actor->save();
                $state['tokens'][] = [
                    'id' => $prefix.$i, 'name' => $actor->name, 'actorId' => $actor->id,
                    'ownerUserId' => $request->user()->id, 'size' => 1, 'hidden' => true,
                    'x' => $data['x'] + ($i % 5) * $state['grid']['size'],
                    'y' => $data['y'] + intdiv($i, 5) * $state['grid']['size'],
                ];
            }
            $scene->state = $state;
            $scene->save();
            broadcast(new SceneUpdated($scene, 'token'));
        }

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    public function deleteToken(Request $request, Scene $scene, string $tokenId): JsonResponse
    {
        $this->requireGm($request, $scene);
        $state = $scene->state;
        $state['tokens'] = array_values(array_filter(
            $state['tokens'],
            fn ($t) => $t['id'] !== $tokenId
        ));
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'token'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }
}
