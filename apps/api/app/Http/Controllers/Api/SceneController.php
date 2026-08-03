<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Controller;
use App\Models\Scene;
use App\Models\SceneMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SceneController extends Controller
{
    public function show(Request $request, Scene $scene): JsonResponse
    {
        $member = $this->requireMember($request, $scene);

        return response()->json([
            'scene' => [
                'id' => $scene->id,
                'name' => $scene->name,
                'role' => $member->role,
                'state' => $scene->state,
                'backgroundUrl' => $scene->background_path
                    ? url('storage/'.$scene->background_path)
                    : null,
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
            'state' => $scene->state,
        ]);
    }

    public function upsertToken(Request $request, Scene $scene): JsonResponse
    {
        $member = $this->requireMember($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'name' => ['nullable', 'string', 'max:80'],
            'ownerUserId' => ['nullable', 'integer'],
            'size' => ['nullable', 'numeric'],
        ]);

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
            ];
        } else {
            $token = $state['tokens'][$existingIndex];
            if (! $member->isGm() && (int) ($token['ownerUserId'] ?? 0) !== (int) $request->user()->id) {
                abort(403, 'Você só pode mover seus próprios tokens.');
            }
            $token['x'] = (float) $data['x'];
            $token['y'] = (float) $data['y'];
            if ($member->isGm() && isset($data['name'])) {
                $token['name'] = $data['name'];
            }
            if ($member->isGm() && array_key_exists('ownerUserId', $data)) {
                $token['ownerUserId'] = $data['ownerUserId'];
            }
            $state['tokens'][$existingIndex] = $token;
        }

        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'token'));

        return response()->json(['state' => $scene->state]);
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

        return response()->json(['state' => $scene->state]);
    }

    public function upsertWall(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'x1' => ['required', 'numeric'],
            'y1' => ['required', 'numeric'],
            'x2' => ['required', 'numeric'],
            'y2' => ['required', 'numeric'],
        ]);

        $state = $scene->state;
        $id = $data['id'] ?? (string) Str::uuid();
        $wall = [
            'id' => $id,
            'x1' => (float) $data['x1'],
            'y1' => (float) $data['y1'],
            'x2' => (float) $data['x2'],
            'y2' => (float) $data['y2'],
        ];
        $idx = collect($state['walls'])->search(fn ($w) => $w['id'] === $id);
        if ($idx === false) {
            $state['walls'][] = $wall;
        } else {
            $state['walls'][$idx] = $wall;
        }
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'wall'));

        return response()->json(['state' => $scene->state]);
    }

    public function upsertDoor(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'x1' => ['required', 'numeric'],
            'y1' => ['required', 'numeric'],
            'x2' => ['required', 'numeric'],
            'y2' => ['required', 'numeric'],
            'open' => ['nullable', 'boolean'],
        ]);

        $state = $scene->state;
        $id = $data['id'] ?? (string) Str::uuid();
        $door = [
            'id' => $id,
            'x1' => (float) $data['x1'],
            'y1' => (float) $data['y1'],
            'x2' => (float) $data['x2'],
            'y2' => (float) $data['y2'],
            'open' => (bool) ($data['open'] ?? false),
        ];
        $idx = collect($state['doors'])->search(fn ($d) => $d['id'] === $id);
        if ($idx === false) {
            $state['doors'][] = $door;
        } else {
            $state['doors'][$idx] = $door;
        }
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'door'));

        return response()->json(['state' => $scene->state]);
    }

    public function toggleDoor(Request $request, Scene $scene, string $doorId): JsonResponse
    {
        $this->requireGm($request, $scene);
        $state = $scene->state;
        $idx = collect($state['doors'])->search(fn ($d) => $d['id'] === $doorId);
        if ($idx === false) {
            abort(404, 'Porta não encontrada.');
        }
        $state['doors'][$idx]['open'] = ! $state['doors'][$idx]['open'];
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'door'));

        return response()->json(['state' => $scene->state]);
    }

    public function upsertLight(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'radius' => ['required', 'numeric', 'min:0'],
        ]);

        $state = $scene->state;
        $id = $data['id'] ?? (string) Str::uuid();
        $light = [
            'id' => $id,
            'x' => (float) $data['x'],
            'y' => (float) $data['y'],
            'radius' => (float) $data['radius'],
        ];
        $idx = collect($state['lights'])->search(fn ($l) => $l['id'] === $id);
        if ($idx === false) {
            $state['lights'][] = $light;
        } else {
            $state['lights'][$idx] = $light;
        }
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'light'));

        return response()->json(['state' => $scene->state]);
    }

    public function paintFog(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'w' => ['required', 'numeric'],
            'h' => ['required', 'numeric'],
            'mode' => ['nullable', 'in:reveal,hide'],
        ]);

        $state = $scene->state;
        $rect = [
            'x' => (float) $data['x'],
            'y' => (float) $data['y'],
            'w' => (float) $data['w'],
            'h' => (float) $data['h'],
        ];
        $mode = $data['mode'] ?? 'reveal';
        if ($mode === 'reveal') {
            $state['fog']['revealed'][] = $rect;
        } else {
            // simple hide: remove overlapping rects that match roughly
            $state['fog']['revealed'] = array_values(array_filter(
                $state['fog']['revealed'],
                fn ($r) => ! (
                    abs($r['x'] - $rect['x']) < 1
                    && abs($r['y'] - $rect['y']) < 1
                    && abs($r['w'] - $rect['w']) < 1
                    && abs($r['h'] - $rect['h']) < 1
                )
            ));
        }
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'fog'));

        return response()->json(['state' => $scene->state]);
    }

    public function updateGrid(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'size' => ['nullable', 'numeric', 'min:8'],
            'offsetX' => ['nullable', 'numeric'],
            'offsetY' => ['nullable', 'numeric'],
            'snap' => ['nullable', 'boolean'],
        ]);
        $state = $scene->state;
        $state['grid'] = array_merge($state['grid'], array_filter($data, fn ($v) => $v !== null));
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'grid'));

        return response()->json(['state' => $scene->state]);
    }

    public function chat(Request $request, Scene $scene, DiceRoller $dice): JsonResponse
    {
        $this->requireMember($request, $scene);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $text = trim($data['text']);
        $state = $scene->state;
        $message = [
            'id' => (string) Str::uuid(),
            'userId' => $user->id,
            'userName' => $user->name,
            'createdAt' => now()->toIso8601String(),
        ];

        if (Str::startsWith(Str::lower($text), '/roll ')) {
            $formula = trim(substr($text, 6));
            try {
                $result = $dice->roll($formula);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $message = array_merge($message, [
                'type' => 'roll',
                'formula' => $result['formula'],
                'total' => $result['total'],
                'detail' => $result['detail'],
                'text' => $result['detail'],
            ]);
        } else {
            $message = array_merge($message, [
                'type' => 'text',
                'text' => $text,
            ]);
        }

        $state['chat'][] = $message;
        // keep last 200 messages
        $state['chat'] = array_slice($state['chat'], -200);
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'chat'));

        return response()->json(['message' => $message, 'state' => $scene->state]);
    }

    private function requireMember(Request $request, Scene $scene): SceneMember
    {
        $member = $scene->memberFor($request->user());
        if (! $member) {
            abort(403, 'Você não faz parte desta cena.');
        }

        return $member;
    }

    private function requireGm(Request $request, Scene $scene): SceneMember
    {
        $member = $this->requireMember($request, $scene);
        if (! $member->isGm()) {
            abort(403, 'Apenas o GM pode fazer isso.');
        }

        return $member;
    }
}
