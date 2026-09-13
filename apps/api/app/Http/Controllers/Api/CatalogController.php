<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\HomebrewEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['spells', 'items', 'monsters', 'classes', 'subclasses', 'races', 'backgrounds', 'feats', 'features', 'rules', 'books', 'adventures', 'bastions', 'vehicles', 'decks', 'recipes', 'psionics', 'rewards', 'deities', 'languages', 'hazards', 'objects', 'cults']), 404);
        $campaign = null;
        $role = null;
        if (in_array($kind, ['books', 'adventures']) || $request->filled('campaignId')) {
            $request->validate(['campaignId' => ['required', 'integer']]);
            $campaign = Campaign::findOrFail($request->integer('campaignId'));
            $role = $campaign->roleFor($request->user());
            abort_unless($role !== null, 403);
        }
        $data = $request->validate([
            'query' => ['nullable', 'string', 'max:150'], 'edition' => ['nullable', 'in:5e-2014,5e-2024'],
            'source' => ['nullable', 'string', 'max:80'], 'level' => ['nullable', 'integer', 'min:0', 'max:20'],
            'id' => ['nullable', 'integer', 'min:1'],
            'classId' => ['nullable', 'integer', 'min:1'],
            'school' => ['nullable', 'in:A,C,D,E,V,I,N,T'],
            'ritual' => ['nullable', 'boolean'],
            'maxLevel' => ['nullable', 'integer', 'between:0,9'],
            'includeInactive' => ['nullable', 'boolean'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $base = CatalogEntry::where('kind', $kind)->when($data['edition'] ?? null, fn ($q, $v) => $q->where('edition', $v));
        if (! ($data['includeInactive'] ?? false)) {
            $base->where('active', true);
        } elseif (! $campaign || $campaign->roleFor($request->user()) !== 'gm') {
            abort(403, 'Apenas o mestre pode consultar entradas inativas.');
        }
        // Older imports included Foundry activity overrides without a spell level.
        if ($kind === 'spells') {
            $base->whereNotNull('level');
        }
        if ($campaign && $campaign->catalog_sources !== null) {
            $base->where(function ($query) use ($campaign, $kind) {
                $query->whereIn('source', $campaign->catalog_sources);
                if ($kind === 'books') {
                    $query->orWhereIn('source', ['PHB', 'XPHB']);
                }
                $query->orWhereIn('id', DB::table('campaign_catalog_shares')->where('campaign_id', $campaign->id)->select('catalog_entry_id'));
            });
        }
        if ($campaign && $role !== 'gm' && in_array($kind, ['books', 'adventures'])) {
            $base->where(function ($query) use ($campaign, $kind) {
                if ($kind === 'books') {
                    $query->whereIn('source', ['PHB', 'XPHB']);
                }
                $query->orWhereIn('id', DB::table('campaign_catalog_shares')->where('campaign_id', $campaign->id)->select('catalog_entry_id'));
            });
        }
        $sources = (clone $base)->select('source')->distinct()->orderBy('source')->pluck('source');
        $query = $base->when($data['query'] ?? null, fn ($q, $v) => $q->where('name', 'like', '%'.addcslashes($v, '%_').'%'))
            ->when($data['source'] ?? null, fn ($q, $v) => $q->where('source', $v));
        if (isset($data['level'])) {
            $query->where('level', $data['level']);
        }
        if (isset($data['id'])) {
            $query->whereKey($data['id']);
        }
        if ($kind === 'spells') {
            if (isset($data['maxLevel'])) {
                $query->where('level', '<=', $data['maxLevel']);
            }
            if (isset($data['school'])) {
                $query->where('data->raw->school', $data['school']);
            }
            if (isset($data['ritual'])) {
                if ($data['ritual']) {
                    $query->where('data->raw->meta->ritual', true);
                } else {
                    $query->where(fn ($q) => $q->whereNull('data->raw->meta->ritual')->orWhere('data->raw->meta->ritual', false));
                }
            }
            if (isset($data['classId'])) {
                $class = CatalogEntry::where('kind', 'classes')->when(! ($data['includeInactive'] ?? false), fn ($q) => $q->where('active', true))->findOrFail($data['classId']);
                abort_unless(! $campaign || $campaign->catalog_sources === null || in_array($class->source, $campaign->catalog_sources), 422, 'A fonte da classe não está habilitada.');
                abort_unless(! isset($data['edition']) || $class->edition === $data['edition'], 422, 'A classe pertence a outra edição.');
                $query->whereJsonContains('data->spellLists', $class->source.':'.$class->name);
            }
        }
        if ($kind === 'subclasses' && isset($data['classId'])) {
            $class = CatalogEntry::where('kind', 'classes')->when(! ($data['includeInactive'] ?? false), fn ($q) => $q->where('active', true))->findOrFail($data['classId']);
            abort_unless(! $campaign || $campaign->catalog_sources === null || in_array($class->source, $campaign->catalog_sources), 422, 'A fonte da classe não está habilitada.');
            abort_unless(! isset($data['edition']) || $class->edition === $data['edition'], 422, 'A classe pertence a outra edição.');
            $query->where('data->raw->className', $class->name)
                ->where(function ($q) use ($class) {
                    $q->where('data->raw->classSource', $class->source)
                        ->orWhereNull('data->raw->classSource');
                });
        }
        $result = $query->orderBy('name')->orderBy('source')->paginate($data['perPage'] ?? 20)->toArray();

        if ($campaign) {
            $shared = DB::table('campaign_catalog_shares')->where('campaign_id', $campaign->id)->pluck('catalog_entry_id')->all();
            $result['data'] = array_map(fn ($entry) => [...$entry, 'shared' => in_array($entry['id'], $shared)], $result['data']);
            $homebrew = HomebrewEntry::query()
                ->where('kind', $kind)
                ->whereHas('package', fn ($q) => $q->where('campaign_id', $campaign->id)->where('enabled', true))
                ->with('package:id,name,version')
                ->when($data['query'] ?? null, fn ($q, $v) => $q->where('name', 'like', '%'.addcslashes($v, '%_').'%'))
                ->orderBy('name')->get()
                ->map(fn ($entry) => [
                    'homebrewId' => $entry->id,
                    'packageId' => $entry->homebrew_package_id,
                    'kind' => $entry->kind,
                    'name' => $entry->name,
                    'slug' => $entry->slug,
                    'version' => $entry->version,
                    'data' => $entry->data,
                    'package' => $entry->package->only(['name', 'version']),
                ])->values();
            $result['homebrew'] = $homebrew;
        }

        return response()->json([...$result, 'sources' => $sources]);
    }

    public function share(Request $request, Campaign $campaign, CatalogEntry $entry): JsonResponse
    {
        abort_unless($campaign->roleFor($request->user()) === 'gm', 403);
        abort_unless(in_array($entry->kind, ['books', 'adventures']), 422);
        abort_unless($entry->active, 422, 'Entradas inativas não podem ser compartilhadas.');
        $data = $request->validate(['shared' => ['required', 'boolean']]);
        $key = ['campaign_id' => $campaign->id, 'catalog_entry_id' => $entry->id];
        $query = DB::table('campaign_catalog_shares');
        if ($data['shared']) {
            $query->insertOrIgnore($key);
        } else {
            $query->where($key)->delete();
        }

        return response()->json(['shared' => $data['shared']]);
    }

    public function sources(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->roleFor($request->user()) !== null, 403);

        return response()->json(['selected' => $campaign->catalog_sources, 'sources' => CatalogEntry::where('active', true)->select('source')->distinct()->orderBy('source')->pluck('source')]);
    }

    public function updateSources(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->roleFor($request->user()) === 'gm', 403);
        $data = $request->validate(['sources' => ['present', 'nullable', 'array', 'max:500'], 'sources.*' => ['string', 'distinct', 'max:80', Rule::exists('catalog_entries', 'source')->where('active', true)]]);
        $campaign->catalog_sources = $data['sources'];
        $campaign->save();

        return response()->json(['selected' => $campaign->catalog_sources]);
    }
}
