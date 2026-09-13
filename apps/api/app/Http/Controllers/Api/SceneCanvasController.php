<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\CampaignAsset;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SceneCanvasController extends Controller
{
    use AuthorizesScene;

    public function drawing(Request $request, Scene $scene): JsonResponse
    {
        $this->requireSceneEditor($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'],
            'kind' => ['sometimes', Rule::in(['freehand', 'rectangle', 'ellipse'])],
            'points' => ['required', 'array', 'min:2', 'max:500'],
            'points.*.x' => ['required', 'numeric'], 'points.*.y' => ['required', 'numeric'],
            'stroke' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'], 'width' => ['sometimes', 'numeric', 'between:1,30'],
            'fill' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'hidden' => ['sometimes', 'boolean'],
        ]);
        $item = [
            'id' => $data['id'] ?? (string) Str::uuid(), 'kind' => $data['kind'] ?? 'freehand',
            'points' => array_map(fn ($point) => ['x' => (float) $point['x'], 'y' => (float) $point['y']], $data['points']),
            'stroke' => $data['stroke'] ?? '#6fc3ff', 'width' => (float) ($data['width'] ?? 3),
            'fill' => $data['fill'] ?? null, 'hidden' => (bool) ($data['hidden'] ?? false),
        ];

        return $this->upsert($request, $scene, 'drawings', $item);
    }

    public function label(Request $request, Scene $scene): JsonResponse
    {
        $this->requireSceneEditor($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'], 'x' => ['required', 'numeric'], 'y' => ['required', 'numeric'],
            'text' => ['required', 'string', 'max:500'], 'fontSize' => ['sometimes', 'integer', 'between:8,96'], 'hidden' => ['sometimes', 'boolean'],
        ]);
        $item = [
            'id' => $data['id'] ?? (string) Str::uuid(), 'x' => (float) $data['x'], 'y' => (float) $data['y'],
            'text' => $data['text'], 'fontSize' => (int) ($data['fontSize'] ?? 20), 'hidden' => (bool) ($data['hidden'] ?? false),
        ];

        return $this->upsert($request, $scene, 'labels', $item);
    }

    public function tile(Request $request, Scene $scene): JsonResponse
    {
        $this->requireSceneEditor($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'], 'assetId' => ['required', 'integer', 'exists:campaign_assets,id'],
            'x' => ['required', 'numeric'], 'y' => ['required', 'numeric'], 'w' => ['required', 'numeric', 'min:4'], 'h' => ['required', 'numeric', 'min:4'],
            'opacity' => ['sometimes', 'numeric', 'between:0,1'], 'rotation' => ['sometimes', 'numeric'], 'hidden' => ['sometimes', 'boolean'],
        ]);
        $asset = CampaignAsset::findOrFail($data['assetId']);
        abort_unless((int) $asset->campaign_id === (int) $scene->campaign_id && $asset->kind === 'image', 422, 'Escolha uma imagem desta campanha.');
        $item = [
            'id' => $data['id'] ?? (string) Str::uuid(), 'assetId' => $asset->id,
            'url' => url('storage/'.$asset->path), 'x' => (float) $data['x'], 'y' => (float) $data['y'],
            'w' => (float) $data['w'], 'h' => (float) $data['h'], 'opacity' => (float) ($data['opacity'] ?? 1),
            'rotation' => (float) ($data['rotation'] ?? 0), 'hidden' => (bool) ($data['hidden'] ?? false),
        ];

        return $this->upsert($request, $scene, 'tiles', $item);
    }

    public function region(Request $request, Scene $scene): JsonResponse
    {
        $this->requireSceneEditor($request, $scene);
        $data = $request->validate([
            'id' => ['nullable', 'string', 'max:64'], 'x' => ['required', 'numeric'], 'y' => ['required', 'numeric'],
            'w' => ['required', 'numeric', 'min:1'], 'h' => ['required', 'numeric', 'min:1'], 'name' => ['required', 'string', 'max:160'],
            'behavior' => ['sometimes', Rule::in(['none', 'difficult-terrain', 'trigger', 'danger'])],
            'note' => ['nullable', 'string', 'max:1000'], 'hidden' => ['sometimes', 'boolean'],
        ]);
        $item = [
            'id' => $data['id'] ?? (string) Str::uuid(), 'x' => (float) $data['x'], 'y' => (float) $data['y'],
            'w' => (float) $data['w'], 'h' => (float) $data['h'], 'name' => $data['name'],
            'behavior' => $data['behavior'] ?? 'none', 'note' => $data['note'] ?? null, 'hidden' => (bool) ($data['hidden'] ?? false),
        ];

        return $this->upsert($request, $scene, 'regions', $item);
    }

    public function ping(Request $request, Scene $scene): JsonResponse
    {
        $this->requireParticipant($request, $scene);
        $data = $request->validate(['x' => ['required', 'numeric'], 'y' => ['required', 'numeric'], 'label' => ['nullable', 'string', 'max:80']]);
        $state = $scene->state;
        $state['pings'] ??= [];
        $state['pings'][] = [
            'id' => (string) Str::uuid(), 'x' => (float) $data['x'], 'y' => (float) $data['y'],
            'label' => $data['label'] ?? null, 'userName' => $request->user()->name, 'createdAt' => now()->toIso8601String(),
        ];
        $state['pings'] = array_slice($state['pings'], -20);
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'ping'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    public function destroy(Request $request, Scene $scene, string $kind, string $id): JsonResponse
    {
        $this->requireSceneEditor($request, $scene);
        abort_unless(in_array($kind, ['drawings', 'labels', 'tiles', 'regions'], true), 404);
        $state = $scene->state;
        $before = count($state[$kind] ?? []);
        $state[$kind] = array_values(array_filter($state[$kind] ?? [], fn ($item) => ($item['id'] ?? null) !== $id));
        abort_if(count($state[$kind]) === $before, 404);
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'canvas'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }

    private function upsert(Request $request, Scene $scene, string $collection, array $item): JsonResponse
    {
        $state = $scene->state;
        $state[$collection] ??= [];
        $index = collect($state[$collection])->search(fn ($current) => ($current['id'] ?? null) === $item['id']);
        if ($index === false) {
            $state[$collection][] = $item;
        } else {
            $state[$collection][$index] = $item;
        }
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'canvas'));

        return response()->json(['state' => $scene->stateFor($request->user())]);
    }
}
