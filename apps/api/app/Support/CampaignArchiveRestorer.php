<?php

namespace App\Support;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\Combat;
use App\Models\CombatParticipant;
use App\Models\EncounterBuilderDraft;
use App\Models\HomebrewEntry;
use App\Models\HomebrewPackage;
use App\Models\Journal;
use App\Models\LootResult;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\PrivateMessage;
use App\Models\RollTable;
use App\Models\RollTableRoll;
use App\Models\Room;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CampaignArchiveRestorer
{
    /** @return array{campaign: Campaign, room: Room, sceneId: int|null} */
    public function restore(array $archive, User $user, ?string $name = null): array
    {
        $restoredMediaPaths = [];
        try {
            [$campaign, $room] = DB::transaction(function () use ($archive, $user, $name, &$restoredMediaPaths) {
                $sourceCampaign = $archive['campaign'];
                $campaign = Campaign::create([
                    'owner_id' => $user->id,
                    'name' => $name ?? (($sourceCampaign['name'] ?? 'Campanha').' · restaurada'),
                    'ruleset' => in_array($sourceCampaign['ruleset'] ?? null, ['5e-2014', '5e-2024'], true) ? $sourceCampaign['ruleset'] : '5e-2014',
                    'catalog_sources' => $sourceCampaign['catalog_sources'] ?? null,
                ]);
                $campaign->house_rules = $sourceCampaign['house_rules'] ?? [];
                $campaign->save();
                $room = Room::create(['campaign_id' => $campaign->id, 'code' => $this->uniqueCode()]);

                $mediaMap = $this->restoreMedia($campaign, $archive['media'] ?? [], $restoredMediaPaths);
                $catalogMap = $this->catalogMap($archive);
                $actorMap = $this->restoreActors($campaign, $archive['actors'], $catalogMap, $mediaMap, $user->id);

                $actionMessageMap = $this->actionMessageMap($archive['actions'] ?? []);
                $sceneMap = $this->restoreScenes($campaign, $archive['scenes'], $actorMap, $catalogMap, $actionMessageMap, $mediaMap, $user);

                $this->restoreJournals($campaign, $archive['journals'], $sceneMap, $catalogMap);

                $this->restoreSecondaryContent($campaign, $archive, $sceneMap, $actorMap, $catalogMap, $mediaMap, $user);

                $this->restoreActionHistory($archive, $sceneMap, $actorMap, $actionMessageMap, $user->id);
                $this->restoreCatalogShares($campaign, $archive, $catalogMap);

                return [$campaign, $room];
            });
        } catch (\Throwable $e) {
            foreach ($restoredMediaPaths as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }

        return ['campaign' => $campaign->fresh(), 'room' => $room, 'sceneId' => $campaign->scenes()->oldest('id')->value('id')];
    }

    private function restoreActors(Campaign $campaign, array $rows, array $catalogMap, array $mediaMap, int $userId): array
    {
        $actorMap = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! in_array($row['type'] ?? null, ['character', 'npc', 'monster'], true) || empty($row['name']) || ! is_array($row['system'] ?? null)) {
                continue;
            }
            $system = $this->remapActorCatalog($row['system'], $catalogMap);
            $actor = Actor::create([
                'campaign_id' => $campaign->id,
                'owner_user_id' => $row['type'] === 'character' ? $userId : null,
                'type' => $row['type'],
                'name' => mb_substr((string) $row['name'], 0, 120),
                'img_path' => $mediaMap[$row['imgPath'] ?? ''] ?? null,
                'system' => $system,
                'shared' => (bool) ($row['shared'] ?? false),
            ]);
            if (isset($row['id'])) {
                $actorMap[(int) $row['id']] = $actor->id;
            }
        }

        return $actorMap;
    }

    private function actionMessageMap(array $actions): array
    {
        $actionMessageMap = [];
        foreach ($actions as $action) {
            $old = is_object($action) ? ($action->message_id ?? null) : ($action['message_id'] ?? null);
            if ($old) {
                $actionMessageMap[(string) $old] = (string) Str::uuid();
            }
        }

        return $actionMessageMap;
    }

    private function restoreScenes(Campaign $campaign, array $rows, array $actorMap, array $catalogMap, array $actionMessageMap, array $mediaMap, User $user): array
    {
        $sceneMap = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name']) || ! is_array($row['state'] ?? null)) {
                continue;
            }
            $state = $this->remapSceneState($row['state'], $actorMap, $catalogMap, $actionMessageMap, $user->id, $user->name);
            $restoredBackground = $mediaMap[$row['background_path'] ?? ''] ?? null;
            if ($restoredBackground) {
                $state['backgroundUrl'] = url('storage/'.$restoredBackground);
            } else {
                unset($state['backgroundUrl']);
            }
            $scene = Scene::create([
                'campaign_id' => $campaign->id,
                'name' => mb_substr((string) $row['name'], 0, 120),
                'published' => (bool) ($row['published'] ?? false),
                'import_key' => isset($row['import_key']) ? 'restore:'.$campaign->id.':'.hash('sha256', (string) $row['import_key']) : null,
                'background_path' => $restoredBackground,
                'state' => $state,
            ]);
            if (isset($row['id'])) {
                $sceneMap[(int) $row['id']] = $scene->id;
            }
            SceneMember::create(['scene_id' => $scene->id, 'user_id' => $user->id, 'role' => 'gm']);
        }
        abort_if(! $sceneMap, 422, 'O backup não contém cenas restauráveis.');
        if (! Scene::where('campaign_id', $campaign->id)->where('published', true)->exists()) {
            Scene::where('campaign_id', $campaign->id)->oldest('id')->first()?->update(['published' => true]);
        }

        return $sceneMap;
    }

    private function restoreJournals(Campaign $campaign, array $rows, array $sceneMap, array $catalogMap): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['title'])) {
                continue;
            }
            $visibility = in_array($row['visibility'] ?? null, ['all', 'gm'], true) ? $row['visibility'] : 'gm';
            $attachments = [];
            foreach ($row['attachments'] ?? [] as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }
                if (($attachment['type'] ?? null) === 'ref' && preg_match('/^catalog:(\d+):(map:\d+|art|token)$/', (string) ($attachment['ref'] ?? ''), $match)) {
                    $catalogId = $catalogMap[(int) $match[1]] ?? null;
                    if (! $catalogId) {
                        continue;
                    }
                    $attachment['ref'] = 'catalog:'.$catalogId.':'.$match[2];
                }
                $attachments[] = $attachment;
            }
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            if (isset($metadata['chapter']['id'])) {
                $mapped = $catalogMap[(int) $metadata['chapter']['id']] ?? null;
                if ($mapped) {
                    $metadata['chapter']['id'] = $mapped;
                } else {
                    unset($metadata['chapter']['id']);
                }
            }
            if (isset($metadata['scene']['id'])) {
                $mapped = $sceneMap[(int) $metadata['scene']['id']] ?? null;
                if ($mapped) {
                    $metadata['scene']['id'] = $mapped;
                } else {
                    unset($metadata['scene']['id']);
                }
            }
            $payload = [
                'campaign_id' => $campaign->id,
                'scene_id' => isset($row['scene_id']) ? ($sceneMap[(int) $row['scene_id']] ?? null) : null,
                'catalog_entry_id' => isset($row['catalog_entry_id']) ? ($catalogMap[(int) $row['catalog_entry_id']] ?? null) : null,
                'title' => mb_substr((string) $row['title'], 0, 160),
                'body' => (string) ($row['body'] ?? ''),
                'visibility' => $visibility,
                'folder' => isset($row['folder']) ? mb_substr((string) $row['folder'], 0, 120) : null,
                'metadata' => $metadata,
                'attachments' => $attachments,
                // IDs de usuários não são portáveis entre instalações. Compartilhamento
                // seletivo volta a privado até o mestre escolher os novos destinatários.
                'shared_user_ids' => [],
            ];
            Journal::create($payload);
        }
    }

    private function restoreSecondaryContent(Campaign $campaign, array $archive, array $sceneMap, array $actorMap, array $catalogMap, array $mediaMap, User $user): void
    {
        $this->restoreCombats($archive['combats'] ?? [], $sceneMap, $actorMap, $mediaMap);
        $this->restorePlaylists($campaign, $archive['playlists'] ?? []);
        $this->restorePrivateMessages($archive['privateMessages'] ?? [], $sceneMap, $user);
        $this->restoreHomebrew($campaign, $archive['homebrewPackages'] ?? []);
        $this->restoreEncounterDrafts($campaign, $archive['encounterDrafts'] ?? [], $catalogMap);
        $rollMap = $this->restoreRollTables($campaign, $archive['rollTables'] ?? [], $user);
        $this->restoreLoot($campaign, $archive['lootResults'] ?? [], $actorMap, $rollMap);
    }

    private function restoreCombats(array $rows, array $sceneMap, array $actorMap, array $mediaMap): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($sceneMap[(int) ($row['scene_id'] ?? 0)])) {
                continue;
            }
            $combat = Combat::create([
                'scene_id' => $sceneMap[(int) $row['scene_id']],
                'round' => max(1, (int) ($row['round'] ?? 1)),
                'turn' => max(0, (int) ($row['turn'] ?? 0)),
                'is_active' => (bool) ($row['is_active'] ?? false),
            ]);
            foreach ($row['participants'] ?? [] as $participant) {
                if (! is_array($participant)) {
                    continue;
                }
                CombatParticipant::create([
                    'combat_id' => $combat->id,
                    'actor_id' => isset($participant['actor_id']) ? ($actorMap[(int) $participant['actor_id']] ?? null) : null,
                    'token_id' => $participant['token_id'] ?? null,
                    'name' => mb_substr((string) ($participant['name'] ?? 'Combatente'), 0, 255),
                    'img_path' => $mediaMap[$participant['img_path'] ?? ''] ?? null,
                    'initiative' => $participant['initiative'] ?? null,
                    'hidden' => (bool) ($participant['hidden'] ?? false),
                    'sort' => max(0, (int) ($participant['sort'] ?? 0)),
                ]);
            }
        }
    }

    private function restorePlaylists(Campaign $campaign, array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }
            $playlist = Playlist::create(['campaign_id' => $campaign->id, 'name' => mb_substr((string) $row['name'], 0, 120)]);
            foreach ($row['tracks'] ?? [] as $track) {
                if (! is_array($track) || empty($track['title']) || ! filter_var($track['url'] ?? null, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower((string) $track['url']), 'https://')) {
                    continue;
                }
                PlaylistTrack::create([
                    'playlist_id' => $playlist->id, 'title' => mb_substr((string) $track['title'], 0, 160), 'url' => $track['url'],
                    'volume' => max(0, min(1, (float) ($track['volume'] ?? .5))), 'loop' => (bool) ($track['loop'] ?? false), 'sort' => max(0, (int) ($track['sort'] ?? 0)),
                ]);
            }
        }
    }

    private function restorePrivateMessages(array $rows, array $sceneMap, User $user): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sceneId = $sceneMap[(int) ($row['scene_id'] ?? 0)] ?? null;
            if (! $sceneId) {
                continue;
            }
            // A restauração cria uma campanha privada do importador. Histórico privado antigo
            // é preservado somente como mensagens do próprio importador para si, sem reter IDs de contas anteriores.
            PrivateMessage::create([
                'scene_id' => $sceneId, 'sender_user_id' => $user->id, 'recipient_user_id' => $user->id,
                'kind' => in_array($row['kind'] ?? null, ['text', 'roll'], true) ? $row['kind'] : 'text',
                'text' => $row['text'] ?? null, 'formula' => $row['formula'] ?? null, 'total' => $row['total'] ?? null,
                'detail' => $row['detail'] ?? null, 'critical' => (bool) ($row['critical'] ?? false), 'fumble' => (bool) ($row['fumble'] ?? false),
            ]);
        }
    }

    private function restoreHomebrew(Campaign $campaign, array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }
            $package = HomebrewPackage::create([
                'campaign_id' => $campaign->id,
                'name' => mb_substr((string) $row['name'], 0, 160),
                'version' => mb_substr((string) ($row['version'] ?? '1.0.0'), 0, 40),
                'enabled' => (bool) ($row['enabled'] ?? true),
                'description' => isset($row['description']) ? mb_substr((string) $row['description'], 0, 5000) : null,
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            ]);
            foreach ($row['entries'] ?? [] as $entry) {
                if (! is_array($entry) || empty($entry['kind']) || empty($entry['name']) || empty($entry['slug']) || ! is_array($entry['data'] ?? null)) {
                    continue;
                }
                HomebrewEntry::create([
                    'homebrew_package_id' => $package->id,
                    'kind' => mb_substr((string) $entry['kind'], 0, 40),
                    'name' => mb_substr((string) $entry['name'], 0, 160),
                    'slug' => mb_substr((string) $entry['slug'], 0, 160),
                    'version' => mb_substr((string) ($entry['version'] ?? '1.0.0'), 0, 40),
                    'data' => $entry['data'],
                ]);
            }
        }
    }

    private function restoreEncounterDrafts(Campaign $campaign, array $rows, array $catalogMap): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }
            $creatures = [];
            foreach ($row['creatures'] ?? [] as $creature) {
                if (! is_array($creature)) {
                    continue;
                }
                $oldCatalogId = (int) ($creature['catalogEntryId'] ?? 0);
                $catalogId = $catalogMap[$oldCatalogId] ?? null;
                if (! $catalogId) {
                    continue;
                }
                $creatures[] = [...$creature, 'catalogEntryId' => $catalogId];
            }
            EncounterBuilderDraft::create([
                'campaign_id' => $campaign->id,
                'name' => mb_substr((string) $row['name'], 0, 160),
                'party' => is_array($row['party'] ?? null) ? $row['party'] : [],
                'creatures' => $creatures,
                'difficulty' => is_array($row['difficulty'] ?? null) ? $row['difficulty'] : null,
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            ]);
        }
    }

    private function restoreRollTables(Campaign $campaign, array $rows, User $user): array
    {
        $rollMap = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name']) || ! is_array($row['entries'] ?? null)) {
                continue;
            }
            $table = RollTable::create([
                'campaign_id' => $campaign->id,
                'name' => mb_substr((string) $row['name'], 0, 160),
                'formula' => mb_substr((string) ($row['formula'] ?? '1d100'), 0, 40),
                'enabled' => (bool) ($row['enabled'] ?? true),
                'entries' => $row['entries'],
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            ]);
            foreach ($row['rolls'] ?? [] as $roll) {
                if (! is_array($roll) || ! isset($roll['total']) || ! is_array($roll['result'] ?? null)) {
                    continue;
                }
                $record = RollTableRoll::create([
                    'roll_table_id' => $table->id,
                    'user_id' => $user->id,
                    'total' => (int) $roll['total'],
                    'result' => $roll['result'],
                    'created_at' => isset($roll['created_at']) ? $roll['created_at'] : now(),
                ]);
                if (isset($roll['oldId'])) {
                    $rollMap[(int) $roll['oldId']] = $record->id;
                }
            }
        }

        return $rollMap;
    }

    private function restoreLoot(Campaign $campaign, array $rows, array $actorMap, array $rollMap): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['name'])) {
                continue;
            }
            $actorId = isset($row['applied_actor_id']) ? ($actorMap[(int) $row['applied_actor_id']] ?? null) : null;
            $status = ($row['status'] ?? 'draft') === 'applied' && $actorId ? 'applied' : 'draft';
            LootResult::create([
                'campaign_id' => $campaign->id,
                'roll_table_roll_id' => isset($row['roll_table_roll_id']) ? ($rollMap[(int) $row['roll_table_roll_id']] ?? null) : null,
                'name' => mb_substr((string) $row['name'], 0, 160),
                'items' => is_array($row['items'] ?? null) ? $row['items'] : [],
                'currency' => is_array($row['currency'] ?? null) ? $row['currency'] : [],
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                'status' => $status,
                'applied_actor_id' => $status === 'applied' ? $actorId : null,
                'applied_at' => $status === 'applied' ? ($row['applied_at'] ?? now()) : null,
            ]);
        }
    }

    private function restoreMedia(Campaign $campaign, array $media, array &$writtenPaths): array
    {
        $map = [];
        $total = 0;
        foreach ($media as $item) {
            if (! is_array($item) || empty($item['path']) || empty($item['contentBase64'])) {
                continue;
            }
            $bytes = base64_decode((string) $item['contentBase64'], true);
            abort_if($bytes === false, 422, 'Arquivo de mídia inválido no backup.');
            $total += strlen($bytes);
            abort_if($total > 75 * 1024 * 1024, 413, 'A mídia do backup ultrapassa 75 MB.');
            if (! empty($item['sha256'])) {
                abort_unless(hash_equals((string) $item['sha256'], hash('sha256', $bytes)), 422, 'A mídia do backup falhou na verificação de integridade.');
            }
            $image = @getimagesizefromstring($bytes);
            $mime = is_array($image) ? $image['mime'] : null;
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            abort_unless(isset($extensions[$mime]), 422, 'O backup contém mídia que não é uma imagem suportada.');
            $path = 'restored/'.$campaign->id.'/'.hash('sha256', $bytes).'.'.$extensions[$mime];
            Storage::disk('public')->put($path, $bytes);
            $writtenPaths[] = $path;
            $map[(string) $item['path']] = $path;
        }

        return $map;
    }

    private function catalogMap(array $archive): array
    {
        $refs = $archive['catalogRefs'] ?? [];
        foreach ($archive['catalogShares'] ?? [] as $share) {
            if (! is_array($share) || empty($share['id']) || empty($share['slug'])) {
                continue;
            }
            $refs[(int) $share['id']] = (string) $share['slug'];
        }
        $slugs = collect($refs)->values()->filter()->unique()->all();
        $bySlug = CatalogEntry::whereIn('slug', $slugs)->pluck('id', 'slug');
        $map = [];
        foreach ($refs as $oldId => $slug) {
            if ($slug && isset($bySlug[$slug])) {
                $map[(int) $oldId] = (int) $bySlug[$slug];
            }
        }

        return $map;
    }

    private function remapActorCatalog(array $system, array $catalogMap): array
    {
        if (isset($system['preparation']['classId'])) {
            $mapped = $catalogMap[(int) $system['preparation']['classId']] ?? null;
            if ($mapped) {
                $system['preparation']['classId'] = $mapped;
            } else {
                unset($system['preparation']);
            }
        }
        foreach ($system['progression']['classes'] ?? [] as $index => $class) {
            if (! isset($class['classId'])) {
                continue;
            }
            $mapped = $catalogMap[(int) $class['classId']] ?? null;
            if ($mapped) {
                $system['progression']['classes'][$index]['classId'] = $mapped;
            } else {
                unset($system['progression']['classes'][$index]['classId']);
            }
        }
        if (isset($system['progression']['subclass']['subclassId'])) {
            $mapped = $catalogMap[(int) $system['progression']['subclass']['subclassId']] ?? null;
            if ($mapped) {
                $system['progression']['subclass']['subclassId'] = $mapped;
            } else {
                unset($system['progression']['subclass']['subclassId']);
            }
        }
        foreach ($system['progression']['subclasses'] ?? [] as $index => $subclass) {
            if (! isset($subclass['subclassId'])) {
                continue;
            }
            $mapped = $catalogMap[(int) $subclass['subclassId']] ?? null;
            if ($mapped) {
                $system['progression']['subclasses'][$index]['subclassId'] = $mapped;
            } else {
                unset($system['progression']['subclasses'][$index]['subclassId']);
            }
        }

        return $system;
    }

    private function remapSceneState(array $state, array $actorMap, array $catalogMap, array $messageMap, int $userId, string $userName): array
    {
        foreach ($state['tokens'] ?? [] as $index => $token) {
            if (isset($token['actorId'])) {
                $state['tokens'][$index]['actorId'] = $actorMap[(int) $token['actorId']] ?? null;
            }
            $state['tokens'][$index]['ownerUserId'] = null;
        }
        if (isset($state['preparation']['entryId'])) {
            $mapped = $catalogMap[(int) $state['preparation']['entryId']] ?? null;
            if ($mapped) {
                $state['preparation']['entryId'] = $mapped;
            } else {
                unset($state['preparation']);
            }
        }
        foreach ($state['chat'] ?? [] as $index => $message) {
            if (isset($messageMap[$message['id'] ?? ''])) {
                $state['chat'][$index]['id'] = $messageMap[$message['id']];
            }
            if (isset($message['sourceActorId'])) {
                $state['chat'][$index]['sourceActorId'] = $actorMap[(int) $message['sourceActorId']] ?? null;
            }
            $state['chat'][$index]['userId'] = $userId;
            $state['chat'][$index]['userName'] = $userName;
        }

        return $state;
    }

    private function restoreActionHistory(array $archive, array $sceneMap, array $actorMap, array $messageMap, int $userId): void
    {
        foreach ($archive['actions'] ?? [] as $raw) {
            $row = (array) $raw;
            $sceneId = $sceneMap[(int) ($row['scene_id'] ?? 0)] ?? null;
            $actorId = $actorMap[(int) ($row['actor_id'] ?? 0)] ?? null;
            $messageId = $messageMap[(string) ($row['message_id'] ?? '')] ?? null;
            if (! $sceneId || ! $actorId || ! $messageId) {
                continue;
            }
            $message = json_decode($row['message'] ?? '{}', true) ?: [];
            $message['id'] = $messageId;
            $message['userId'] = $userId;
            if (isset($message['sourceActorId'])) {
                $message['sourceActorId'] = $actorMap[(int) $message['sourceActorId']] ?? null;
            }
            DB::table('action_records')->insert([
                'scene_id' => $sceneId,
                'actor_id' => $actorId,
                'user_id' => $userId,
                'request_id' => (string) Str::uuid(),
                'message_id' => $messageId,
                'message' => json_encode($message, JSON_THROW_ON_ERROR),
                'saves' => $this->remapSaves($row['saves'] ?? null, $actorMap),
                'resource_before' => $row['resource_before'] ?? null,
                'resource_after' => $row['resource_after'] ?? null,
                'undone' => (bool) ($row['undone'] ?? false),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($archive['damageApplications'] ?? [] as $raw) {
            $row = (array) $raw;
            $sceneId = $sceneMap[(int) ($row['scene_id'] ?? 0)] ?? null;
            $actorId = $actorMap[(int) ($row['actor_id'] ?? 0)] ?? null;
            $messageId = $messageMap[(string) ($row['message_id'] ?? '')] ?? null;
            if (! $sceneId || ! $actorId || ! $messageId) {
                continue;
            }
            DB::table('damage_applications')->insert([
                'scene_id' => $sceneId, 'actor_id' => $actorId, 'message_id' => $messageId,
                'before' => $row['before'], 'after' => $row['after'], 'resolution' => $row['resolution'] ?? null,
                'undone' => (bool) ($row['undone'] ?? false),
            ]);
        }
    }

    private function remapSaves(mixed $raw, array $actorMap): ?string
    {
        if ($raw === null) {
            return null;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return null;
        }
        $mapped = [];
        foreach ($decoded as $oldActorId => $save) {
            $actorId = $actorMap[(int) $oldActorId] ?? null;
            if (! $actorId) {
                continue;
            }
            if (is_array($save)) {
                unset($save['userId']);
            }
            $mapped[(string) $actorId] = $save;
        }

        return $mapped ? json_encode($mapped, JSON_THROW_ON_ERROR) : null;
    }

    private function restoreCatalogShares(Campaign $campaign, array $archive, array $catalogMap): void
    {
        foreach ($archive['catalogShares'] ?? [] as $share) {
            $oldId = is_array($share) ? ($share['id'] ?? null) : $share;
            $slug = is_array($share) ? ($share['slug'] ?? null) : null;
            $id = $oldId ? ($catalogMap[(int) $oldId] ?? null) : null;
            if (! $id && $slug) {
                $id = CatalogEntry::where('slug', $slug)->value('id');
            }
            if ($id) {
                DB::table('campaign_catalog_shares')->insertOrIgnore(['campaign_id' => $campaign->id, 'catalog_entry_id' => $id]);
            }
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (Room::where('code', $code)->exists());

        return $code;
    }
}
