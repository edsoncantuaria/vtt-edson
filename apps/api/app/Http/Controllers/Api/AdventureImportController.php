<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\EncounterBuilderDraft;
use App\Models\SceneMember;
use App\Support\AdventureEncounterHints;
use App\Support\AdventureMaps;
use App\Support\EncounterBuilderDifficulty;
use App\Support\SceneStateFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdventureImportController extends Controller
{
    private function authorizeImport(Request $request, Campaign $campaign, CatalogEntry $entry): void
    {
        abort_unless($campaign->canManage($request->user()), 403);
        abort_unless($entry->kind === 'adventures', 422);
        abort_unless($campaign->catalog_sources === null || in_array($entry->source, $campaign->catalog_sources), 422, 'Habilite esta fonte na biblioteca da campanha antes de importar.');
    }

    public function preview(Request $request, Campaign $campaign, CatalogEntry $entry)
    {
        $this->authorizeImport($request, $campaign, $entry);
        $raw = $entry->data['raw'] ?? [];

        return response()->json([
            'maps' => AdventureMaps::extract($raw),
            'encounterHints' => AdventureEncounterHints::extract($raw),
            'sourceHash' => hash('sha256', json_encode($entry->data)),
            'book' => ['source' => $entry->source, 'name' => $entry->data['bookName'] ?? $entry->data['book'] ?? $entry->source],
            'chapter' => ['id' => $entry->id, 'slug' => $entry->slug, 'name' => $entry->name],
        ]);
    }

    public function store(Request $request, Campaign $campaign, CatalogEntry $entry)
    {
        $this->authorizeImport($request, $campaign, $entry);
        $data = $request->validate(['maps' => ['required', 'array', 'min:1', 'max:10'], 'maps.*' => ['integer', 'min:0', 'distinct']]);
        $raw = $entry->data['raw'] ?? [];
        $maps = AdventureMaps::extract($raw);
        $encounterHints = AdventureEncounterHints::extract($raw);
        foreach ($data['maps'] as $index) {
            abort_unless(isset($maps[$index]), 422);
        }
        $scenes = DB::transaction(function () use ($campaign, $entry, $maps, $data, $encounterHints) {
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $members = SceneMember::whereIn('scene_id', $campaign->scenes()->pluck('id'))->get()->unique('user_id');
            $created = [];
            foreach ($data['maps'] as $index) {
                $map = $maps[$index];
                $key = $entry->slug.':map:'.$index;
                $pinLabels = array_map(fn ($pin) => mb_strtolower((string) ($pin['label'] ?? '')), $map['pins'] ?? []);
                $mapHints = array_values(array_filter($encounterHints, function ($hint) use ($pinLabels) {
                    $label = mb_strtolower((string) ($hint['area'] ?? $hint['label'] ?? ''));

                    return $label !== '' && in_array($label, $pinLabels, true);
                }));
                $existing = $campaign->scenes()->where('import_key', $key)->first();
                if ($existing) {
                    $note = $campaign->journals()->where('scene_id', $existing->id)->where('catalog_entry_id', $entry->id)->first();
                    $created[] = ['id' => $existing->id, 'name' => $existing->name, 'existing' => true, 'journalId' => $note?->id];

                    continue;
                }
                $state = SceneStateFactory::empty();
                $state['preparation'] = ['entryId' => $entry->id, 'chapter' => $entry->name, 'source' => $entry->source, 'sourceHash' => hash('sha256', json_encode($entry->data)), 'reviewed' => false];
                $state['backgroundUrl'] = '/api/catalog-media/'.$entry->id.'/map-'.$index;
                if (($map['grid']['type'] ?? '') === 'square') {
                    foreach (['size', 'offsetX', 'offsetY'] as $field) {
                        if (is_numeric($map['grid'][$field] ?? null)) {
                            $state['grid'][$field] = $map['grid'][$field];
                        }
                    }
                }
                $state['grid']['size'] = max(8, $state['grid']['size']);
                $scene = $campaign->scenes()->create(['name' => mb_substr($map['title'], 0, 120), 'import_key' => $key, 'published' => false, 'state' => $state]);
                foreach ($members as $member) {
                    $scene->members()->create(['user_id' => $member->user_id, 'role' => $member->role]);
                }
                $scene->members()->firstOrCreate(['user_id' => $campaign->owner_id], ['role' => 'gm']);
                $encounterDraft = null;
                if ($mapHints) {
                    $names = array_values(array_unique(array_map(fn ($hint) => (string) data_get($hint, 'creature.name', ''), $mapHints)));
                    $catalog = CatalogEntry::query()->where('kind', 'monsters')->where('active', true)->where('edition', $campaign->ruleset)->whereIn('name', $names)->get();
                    $choices = [];
                    foreach ($mapHints as $hint) {
                        $name = (string) data_get($hint, 'creature.name', '');
                        $source = (string) data_get($hint, 'creature.source', '');
                        $matches = $catalog->where('name', $name);
                        $matched = $source !== '' ? ($matches->firstWhere('source', $source) ?? $matches->first()) : $matches->first();
                        if (! $matched) {
                            continue;
                        }
                        $choices[$matched->id] = ($choices[$matched->id] ?? 0) + max(1, (int) ($hint['quantity'] ?? 1));
                    }
                    if ($choices) {
                        $creatures = [];
                        foreach ($choices as $catalogId => $quantity) {
                            $monster = $catalog->firstWhere('id', $catalogId);
                            if ($monster) {
                                $creatures[] = EncounterBuilderDifficulty::creature($monster, min(50, $quantity));
                            }
                        }
                        if ($creatures) {
                            $encounterDraft = EncounterBuilderDraft::create([
                                'campaign_id' => $campaign->id,
                                'name' => mb_substr($entry->name.' · '.$map['title'], 0, 160),
                                'party' => [],
                                'creatures' => $creatures,
                                'difficulty' => EncounterBuilderDifficulty::summarize($creatures, [], $campaign->ruleset),
                                'metadata' => ['source' => 'adventure-import', 'catalogEntryId' => $entry->id, 'sceneId' => $scene->id, 'mapIndex' => $index, 'hints' => $mapHints],
                            ]);
                        }
                    }
                }
                $journal = $campaign->journals()->create([
                    'catalog_entry_id' => $entry->id,
                    'scene_id' => $scene->id,
                    'folder' => mb_substr('Aventuras/'.$entry->source, 0, 120),
                    'title' => mb_substr($entry->name.' · '.$map['title'], 0, 160),
                    'visibility' => 'gm',
                    'shared_user_ids' => [],
                    'body' => mb_substr(($entry->data['description'] ?? '')."\n\nFonte: ".$entry->source."\nMapa importado; revise grade, paredes, portas e encontros antes de publicar.", 0, 50000),
                    'attachments' => [['type' => 'ref', 'label' => $map['title'], 'ref' => 'catalog:'.$entry->id.':map:'.$index]],
                    'metadata' => [
                        'book' => ['source' => $entry->source, 'name' => $entry->data['bookName'] ?? $entry->data['book'] ?? $entry->source],
                        'chapter' => ['id' => $entry->id, 'slug' => $entry->slug, 'name' => $entry->name],
                        'scene' => ['id' => $scene->id, 'name' => $scene->name],
                        'map' => ['index' => $index, 'title' => $map['title'], 'player' => $map['player'], 'grid' => $map['grid'], 'metadata' => $map['metadata'] ?? []],
                        'pins' => $map['pins'] ?? [],
                        'encounters' => $map['encounters'] ?? [],
                        'encounterHints' => $mapHints,
                        'encounterDraftId' => $encounterDraft?->id,
                        'sourceHash' => hash('sha256', json_encode($entry->data)),
                    ],
                ]);
                $created[] = ['id' => $scene->id, 'name' => $scene->name, 'existing' => false, 'journalId' => $journal->id, 'encounterDraftId' => $encounterDraft?->id, 'pins' => count($map['pins'] ?? []), 'encounters' => count($map['encounters'] ?? [])];
            }

            return $created;
        });

        return response()->json(['scenes' => $scenes], 201);
    }
}
