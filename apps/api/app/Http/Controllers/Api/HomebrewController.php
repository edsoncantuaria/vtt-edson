<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\HomebrewEntry;
use App\Models\HomebrewPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HomebrewController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        $query = HomebrewPackage::query()->where('campaign_id', $campaign->id)->with('entries')->orderBy('name');
        if ($campaign->roleFor($request->user()) !== 'gm') {
            $query->where('enabled', true);
        }

        return response()->json(['packages' => $query->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'version' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'], 'enabled' => ['sometimes', 'boolean'], 'metadata' => ['nullable', 'array'],
        ]);
        $package = HomebrewPackage::create([...$data, 'campaign_id' => $campaign->id, 'enabled' => $data['enabled'] ?? true]);

        return response()->json(['package' => $package->load('entries')], 201);
    }

    public function update(Request $request, HomebrewPackage $homebrewPackage): JsonResponse
    {
        $this->gm($request, $homebrewPackage->campaign);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'], 'version' => ['sometimes', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:5000'], 'enabled' => ['sometimes', 'boolean'], 'metadata' => ['nullable', 'array'],
        ]);
        $homebrewPackage->update($data);

        return response()->json(['package' => $homebrewPackage->fresh()->load('entries')]);
    }

    public function storeEntry(Request $request, HomebrewPackage $homebrewPackage): JsonResponse
    {
        $this->gm($request, $homebrewPackage->campaign);
        $data = $this->entryData($request, false);
        $entry = HomebrewEntry::create([...$data, 'homebrew_package_id' => $homebrewPackage->id]);

        return response()->json(['entry' => $entry], 201);
    }

    public function updateEntry(Request $request, HomebrewEntry $homebrewEntry): JsonResponse
    {
        $this->gm($request, $homebrewEntry->package->campaign);
        $homebrewEntry->update($this->entryData($request, true));

        return response()->json(['entry' => $homebrewEntry->fresh()]);
    }

    public function destroyEntry(Request $request, HomebrewEntry $homebrewEntry): JsonResponse
    {
        $this->gm($request, $homebrewEntry->package->campaign);
        $homebrewEntry->delete();

        return response()->json([], 204);
    }

    private function entryData(Request $request, bool $update): array
    {
        $data = $request->validate([
            'kind' => [$update ? 'sometimes' : 'required', 'string', 'max:40'],
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160'], 'version' => [$update ? 'sometimes' : 'required', 'string', 'max:40'],
            'data' => [$update ? 'sometimes' : 'required', 'array'],
        ]);
        if (array_key_exists('slug', $data)) {
            $data['slug'] = Str::slug($data['slug'] ?: ($data['name'] ?? 'entry'));
        } elseif (! $update) {
            $data['slug'] = Str::slug($data['name']);
        }

        return $data;
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->roleFor($request->user()) === 'gm', 403, 'Apenas o GM pode editar homebrew.');
    }
}
