<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SceneGeometryController extends Controller
{
    use AuthorizesScene;

    public function deleteGeometry(Request $request, Scene $scene, string $kind, string $objectId): JsonResponse
    {
        $this->requireGm($request, $scene);
        abort_unless(in_array($kind, ['walls', 'doors', 'lights']), 404);

        return DB::transaction(function () use ($scene, $kind, $objectId) {
            $locked = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();
            $state = $locked->state;
            abort_unless(collect($state[$kind])->contains(fn ($item) => $item['id'] === $objectId), 404);
            $state[$kind] = array_values(array_filter($state[$kind], fn ($item) => $item['id'] !== $objectId));
            $locked->state = $state;
            $locked->save();
            DB::afterCommit(fn () => broadcast(new SceneUpdated($locked, $kind)));

            return response()->json(['state' => $locked->state]);
        });
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

        return response()->json(['state' => $scene->stateFor($request->user())]);
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
            'state' => ['nullable', 'in:normal,locked,secret'],
            'perceptionDc' => ['nullable', 'integer', 'between:1,40'],
        ]);

        $state = $scene->state;
        $id = $data['id'] ?? (string) Str::uuid();
        $idx = collect($state['doors'])->search(fn ($d) => $d['id'] === $id);
        $current = $idx === false ? null : $state['doors'][$idx];
        $doorState = $data['state'] ?? ($current['state'] ?? 'normal');
        $open = $doorState === 'locked'
            ? false
            : (bool) ($data['open'] ?? ($current['open'] ?? false));
        $door = [
            'id' => $id,
            'x1' => (float) $data['x1'],
            'y1' => (float) $data['y1'],
            'x2' => (float) $data['x2'],
            'y2' => (float) $data['y2'],
            'open' => $open,
            'state' => $doorState,
            'perceptionDc' => $data['perceptionDc'] ?? ($current['perceptionDc'] ?? null),
        ];
        if ($idx === false) {
            $state['doors'][] = $door;
        } else {
            $state['doors'][$idx] = $door;
        }
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'door'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    public function toggleDoor(Request $request, Scene $scene, string $doorId): JsonResponse
    {
        $this->requireGm($request, $scene);
        $state = $scene->state;
        $idx = collect($state['doors'])->search(fn ($d) => $d['id'] === $doorId);
        if ($idx === false) {
            abort(404, 'Porta não encontrada.');
        }
        if (($state['doors'][$idx]['state'] ?? 'normal') === 'locked') {
            abort(422, 'Destranque a porta antes de abri-la.');
        }
        $state['doors'][$idx]['open'] = ! $state['doors'][$idx]['open'];
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'door'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
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

        return response()->json(['state' => $scene->stateFor($request->user())]);
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

        return response()->json(['state' => $scene->stateFor($request->user())]);
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

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    public function updateVision(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'dynamic' => ['required', 'boolean'],
            'darkness' => ['sometimes', 'boolean'],
            'normalVisionFeet' => ['sometimes', 'integer', 'between:5,300'],
        ]);
        $state = $scene->state;
        $state['vision'] = [
            'dynamic' => (bool) $data['dynamic'],
            'darkness' => (bool) ($data['darkness'] ?? ($state['vision']['darkness'] ?? false)),
            'normalVisionFeet' => (int) ($data['normalVisionFeet'] ?? ($state['vision']['normalVisionFeet'] ?? 60)),
        ];
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'vision'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    public function updateAudio(Request $request, Scene $scene): JsonResponse
    {
        $this->requireGm($request, $scene);
        $data = $request->validate([
            'url' => ['nullable', 'url', 'max:2000', 'regex:/^https:\/\//i'],
            'volume' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'loop' => ['nullable', 'boolean'],
        ]);
        $state = $scene->state;
        $audio = $state['audio'] ?? ['url' => null, 'volume' => 0.5, 'loop' => true];
        $state['audio'] = array_merge($audio, array_filter($data, fn ($value) => $value !== null));
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'audio'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }
}
