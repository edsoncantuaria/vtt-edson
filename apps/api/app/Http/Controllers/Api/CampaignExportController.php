<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\Combat;
use App\Models\EncounterBuilderDraft;
use App\Models\HomebrewPackage;
use App\Models\LootResult;
use App\Models\Playlist;
use App\Models\PrivateMessage;
use App\Models\RollTable;
use App\Models\RollTableRoll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CampaignExportController extends Controller
{
    public function show(Request $request, Campaign $campaign)
    {
        abort_unless($campaign->roleFor($request->user()) === 'gm', 403);
        $data = $request->validate(['includeMedia' => ['sometimes', 'boolean']]);
        $includeMedia = $data['includeMedia'] ?? true;
        $archive = DB::transaction(function () use ($campaign, $includeMedia) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $campaign = $campaign->fresh();
            $scenes = $campaign->scenes()->orderBy('id')->get();
            $actors = $campaign->actors()->orderBy('id')->get();
            $journals = $campaign->journals()->orderBy('id')->get();
            $catalogIds = collect(DB::table('campaign_catalog_shares')->where('campaign_id', $campaign->id)->pluck('catalog_entry_id'));
            foreach ($scenes as $scene) {
                $entryId = data_get($scene->state, 'preparation.entryId');
                if ($entryId) {
                    $catalogIds->push((int) $entryId);
                }
            }
            foreach ($actors as $actor) {
                $classId = data_get($actor->system, 'preparation.classId');
                if ($classId) {
                    $catalogIds->push((int) $classId);
                }
                foreach (data_get($actor->system, 'progression.classes', []) as $class) {
                    if (! empty($class['classId'])) {
                        $catalogIds->push((int) $class['classId']);
                    }
                }
                $subclassId = data_get($actor->system, 'progression.subclass.subclassId');
                if ($subclassId) {
                    $catalogIds->push((int) $subclassId);
                }
                foreach (data_get($actor->system, 'progression.subclasses', []) as $subclass) {
                    if (! empty($subclass['subclassId'])) {
                        $catalogIds->push((int) $subclass['subclassId']);
                    }
                }
            }
            foreach (EncounterBuilderDraft::where('campaign_id', $campaign->id)->get() as $draft) {
                foreach ($draft->creatures ?? [] as $creature) {
                    if (! empty($creature['catalogEntryId'])) {
                        $catalogIds->push((int) $creature['catalogEntryId']);
                    }
                }
            }
            foreach ($journals as $journal) {
                if ($journal->catalog_entry_id) {
                    $catalogIds->push((int) $journal->catalog_entry_id);
                }
                $chapterId = data_get($journal->metadata, 'chapter.id');
                if ($chapterId) {
                    $catalogIds->push((int) $chapterId);
                }
                foreach ($journal->attachments ?? [] as $attachment) {
                    if (($attachment['type'] ?? null) === 'ref' && preg_match('/^catalog:(\d+):/', (string) ($attachment['ref'] ?? ''), $match)) {
                        $catalogIds->push((int) $match[1]);
                    }
                }
            }
            $catalogRefs = CatalogEntry::whereIn('id', $catalogIds->unique()->values())->get(['id', 'slug'])->pluck('slug', 'id');

            $media = [];
            if ($includeMedia) {
                $paths = $scenes->pluck('background_path')->merge($actors->pluck('img_path'))->filter()->unique();
                $totalBytes = 0;
                foreach ($paths as $path) {
                    if (! Storage::disk('public')->exists($path)) {
                        continue;
                    }
                    $bytes = Storage::disk('public')->get($path);
                    $totalBytes += strlen($bytes);
                    abort_if($totalBytes > 75 * 1024 * 1024, 413, 'O backup com mídia ultrapassa 75 MB. Exporte com includeMedia=0 ou remova arquivos grandes.');
                    $media[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'contentBase64' => base64_encode($bytes)];
                }
            }

            $combats = Combat::whereIn('scene_id', $scenes->modelKeys())->orderBy('id')->get();
            $rollTables = RollTable::where('campaign_id', $campaign->id)->with('rolls')->orderBy('id')->get();

            return [
                'format' => 'vtt-edson-campaign', 'version' => 2, 'exportedAt' => now()->toIso8601String(),
                'campaign' => $campaign->only(['name', 'ruleset', 'catalog_sources', 'house_rules']),
                'actors' => $actors->map(fn ($actor) => [
                    'id' => $actor->id,
                    'type' => $actor->type,
                    'name' => $actor->name,
                    'imgPath' => $actor->img_path,
                    'system' => $actor->system,
                    'shared' => (bool) $actor->shared,
                ]),
                'scenes' => $scenes->map(fn ($scene) => $this->portableScene($scene)),
                'journals' => $journals->map(fn ($journal) => $journal->only([
                    'scene_id', 'catalog_entry_id', 'title', 'body', 'visibility', 'folder', 'metadata', 'attachments',
                ])),
                'combats' => $combats->map(fn ($combat) => [
                    ...$combat->only(['scene_id', 'round', 'turn', 'is_active']),
                    'participants' => $combat->participants()->orderBy('sort')->get()->map(fn ($participant) => $participant->only([
                        'actor_id', 'token_id', 'name', 'img_path', 'initiative', 'hidden', 'sort',
                    ])),
                ]),
                'playlists' => Playlist::where('campaign_id', $campaign->id)->with('tracks')->orderBy('id')->get()->map(fn ($playlist) => [
                    'name' => $playlist->name,
                    'tracks' => $playlist->tracks->map(fn ($track) => $track->only(['title', 'url', 'volume', 'loop', 'sort'])),
                ]),
                'privateMessages' => PrivateMessage::whereIn('scene_id', $scenes->modelKeys())->orderBy('id')->get()->map(fn ($message) => $message->only([
                    'scene_id', 'kind', 'text', 'formula', 'total', 'detail', 'critical', 'fumble',
                ])),
                'actions' => DB::table('action_records')->whereIn('scene_id', $scenes->modelKeys())->orderBy('id')->get([
                    'scene_id', 'actor_id', 'message_id', 'message', 'saves', 'resource_before', 'resource_after', 'undone',
                ])->map(fn ($action) => $this->portableAction($action)),
                'damageApplications' => DB::table('damage_applications')->whereIn('scene_id', $scenes->modelKeys())->orderBy('id')->get([
                    'scene_id', 'actor_id', 'message_id', 'before', 'after', 'resolution', 'undone',
                ]),
                'catalogShares' => DB::table('campaign_catalog_shares')->where('campaign_id', $campaign->id)->pluck('catalog_entry_id')->map(fn ($id) => ['id' => $id, 'slug' => $catalogRefs[$id] ?? null]),
                'catalogRefs' => $catalogRefs,
                'homebrewPackages' => HomebrewPackage::where('campaign_id', $campaign->id)->with('entries')->orderBy('id')->get()->map(fn ($package) => [
                    'name' => $package->name, 'version' => $package->version, 'enabled' => (bool) $package->enabled,
                    'description' => $package->description, 'metadata' => $package->metadata,
                    'entries' => $package->entries->map(fn ($entry) => $entry->only(['kind', 'name', 'slug', 'version', 'data'])),
                ]),
                'encounterDrafts' => EncounterBuilderDraft::where('campaign_id', $campaign->id)->orderBy('id')->get()->map(fn ($draft) => $draft->only([
                    'name', 'party', 'creatures', 'difficulty', 'metadata',
                ])),
                'rollTables' => $rollTables->map(fn (RollTable $table) => [
                    'oldId' => $table->id, 'name' => $table->name, 'formula' => $table->formula, 'enabled' => (bool) $table->enabled,
                    'entries' => $table->entries, 'metadata' => $table->metadata,
                    'rolls' => $table->rolls->map(fn (RollTableRoll $roll) => [
                        'oldId' => $roll->id, 'total' => $roll->total, 'result' => $roll->result, 'created_at' => $roll->created_at,
                    ]),
                ]),
                'lootResults' => LootResult::where('campaign_id', $campaign->id)->orderBy('id')->get()->map(fn ($loot) => $loot->only([
                    'roll_table_roll_id', 'name', 'items', 'currency', 'metadata', 'status', 'applied_actor_id', 'applied_at',
                ])),
                'media' => $media,
                'remoteMediaNote' => 'URLs remotas continuam referências e dependem da origem. Arquivos enviados localmente estão em media quando includeMedia=1.',
            ];
        });

        return response()->json($archive)->header('Content-Disposition', 'attachment; filename="campanha-'.$campaign->id.'.json"');
    }

    private function portableScene($scene): array
    {
        $payload = $scene->only(['id', 'name', 'published', 'import_key', 'background_path', 'state']);
        $state = $payload['state'];
        foreach ($state['tokens'] ?? [] as $index => $token) {
            unset($state['tokens'][$index]['ownerUserId']);
        }
        foreach ($state['chat'] ?? [] as $index => $message) {
            unset($state['chat'][$index]['userId']);
        }
        $payload['state'] = $state;

        return $payload;
    }

    private function portableAction(object $action): array
    {
        $row = (array) $action;
        $message = json_decode($row['message'] ?? '{}', true) ?: [];
        unset($message['userId']);
        $row['message'] = json_encode($message, JSON_THROW_ON_ERROR);
        $saves = json_decode($row['saves'] ?? 'null', true);
        if (is_array($saves)) {
            foreach ($saves as &$save) {
                if (is_array($save)) {
                    unset($save['userId']);
                }
            }
            unset($save);
            $row['saves'] = json_encode($saves, JSON_THROW_ON_ERROR);
        }

        return $row;
    }
}
