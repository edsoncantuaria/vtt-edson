<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\EncounterBuilderDraft;
use App\Models\HomebrewEntry;
use App\Models\HomebrewPackage;
use App\Models\Journal;
use App\Models\LootResult;
use App\Models\RollTable;
use App\Models\RollTableRoll;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignBackupTest extends TestCase
{
    use RefreshDatabase;

    private function room(): array
    {
        Sanctum::actingAs(User::factory()->create());

        return $this->postJson('/api/rooms', ['name' => 'Backup'])->assertCreated()->json();
    }

    public function test_v2_backup_is_portable_sanitizes_external_ids_and_restores_domain_references(): void
    {
        Storage::fake('public');
        $room = $this->room();
        $campaignId = $room['campaign']['id'];
        $scene = Scene::findOrFail($room['scene']['id']);
        $catalog = CatalogEntry::create([
            'slug' => hash('sha256', 'goblin'), 'kind' => 'monsters', 'name' => 'Goblin', 'source' => 'MM',
            'edition' => '5e-2014', 'data' => ['format' => '5etools', 'raw' => ['cr' => '1/4']],
        ]);
        $class = CatalogEntry::create([
            'slug' => hash('sha256', 'wizard'), 'kind' => 'classes', 'name' => 'Wizard', 'source' => 'PHB',
            'edition' => '5e-2014', 'data' => ['format' => '5etools'],
        ]);

        $system = ActorStateFactory::character();
        $system['preparation'] = ['classId' => $class->id, 'source' => 'PHB', 'edition' => '5e-2014', 'tasks' => []];
        $system['progression'] = ['classes' => [['classId' => $class->id, 'name' => 'Wizard', 'source' => 'PHB', 'level' => 1]], 'subclass' => null];
        $actor = Actor::create(['campaign_id' => $campaignId, 'owner_user_id' => auth()->id(), 'type' => 'character', 'name' => 'Hero', 'system' => $system]);

        Storage::disk('public')->put('scene/background.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $state = $scene->state;
        $state['tokens'] = [['id' => 'hero', 'name' => 'Hero', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $actor->id, 'ownerUserId' => auth()->id()]];
        $state['chat'] = [['id' => 'note', 'userId' => auth()->id(), 'userName' => 'Old user', 'type' => 'text', 'text' => 'hello']];
        $scene->update(['background_path' => 'scene/background.png', 'state' => $state]);

        $package = HomebrewPackage::create(['campaign_id' => $campaignId, 'name' => 'Casa', 'version' => '2.0', 'enabled' => true]);
        HomebrewEntry::create(['homebrew_package_id' => $package->id, 'kind' => 'monsters', 'name' => 'Slime', 'slug' => 'slime', 'version' => '2.0', 'data' => ['hp' => 12]]);
        Journal::create([
            'campaign_id' => $campaignId, 'scene_id' => $scene->id, 'catalog_entry_id' => $catalog->id,
            'title' => 'Capítulo', 'body' => 'Nota', 'visibility' => 'gm', 'folder' => null,
            'metadata' => ['chapter' => ['id' => $catalog->id]],
            'attachments' => [['type' => 'ref', 'ref' => 'catalog:'.$catalog->id.':art']],
            'shared_user_ids' => [],
        ]);
        EncounterBuilderDraft::create(['campaign_id' => $campaignId, 'name' => 'Emboscada', 'party' => [['level' => 1]], 'creatures' => [['catalogEntryId' => $catalog->id, 'name' => 'Goblin', 'source' => 'MM', 'cr' => '1/4', 'xp' => 50, 'quantity' => 2]], 'difficulty' => [], 'metadata' => []]);
        $table = RollTable::create(['campaign_id' => $campaignId, 'name' => 'Tesouro', 'formula' => '1d6', 'enabled' => true, 'entries' => [['min' => 1, 'max' => 6, 'label' => 'Moedas']], 'metadata' => []]);
        $roll = RollTableRoll::create(['roll_table_id' => $table->id, 'user_id' => auth()->id(), 'total' => 4, 'result' => ['entry' => ['label' => 'Moedas']]]);
        LootResult::create(['campaign_id' => $campaignId, 'roll_table_roll_id' => $roll->id, 'name' => 'Baú', 'items' => [], 'currency' => ['gp' => 10], 'metadata' => [], 'status' => 'applied', 'applied_actor_id' => $actor->id, 'applied_at' => now()]);

        $archive = $this->getJson('/api/campaigns/'.$campaignId.'/export')->assertOk()->json();
        $this->assertSame(2, $archive['version']);
        $this->assertArrayNotHasKey('id', $archive['campaign']);
        $this->assertArrayNotHasKey('ownerUserId', $archive['actors'][0]);
        $this->assertArrayNotHasKey('campaignId', $archive['actors'][0]);
        $this->assertArrayNotHasKey('ownerUserId', $archive['scenes'][0]['state']['tokens'][0]);
        $this->assertArrayNotHasKey('userId', $archive['scenes'][0]['state']['chat'][0]);
        $this->assertArrayNotHasKey('sender_user_id', $archive['privateMessages'][0] ?? []);

        // Force catalog IDs to differ from the source installation while keeping portable slugs.
        $oldCatalogId = $catalog->id;
        $oldClassId = $class->id;
        $catalog->delete();
        $class->delete();
        CatalogEntry::create(['slug' => hash('sha256', 'padding'), 'kind' => 'items', 'name' => 'Padding', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => []]);
        $newCatalog = CatalogEntry::create(['slug' => hash('sha256', 'goblin'), 'kind' => 'monsters', 'name' => 'Goblin', 'source' => 'MM', 'edition' => '5e-2014', 'data' => ['format' => '5etools']]);
        $newClass = CatalogEntry::create(['slug' => hash('sha256', 'wizard'), 'kind' => 'classes', 'name' => 'Wizard', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => ['format' => '5etools']]);
        $this->assertNotSame($oldCatalogId, $newCatalog->id);
        $this->assertNotSame($oldClassId, $newClass->id);
        $restored = $this->postJson('/api/campaigns/import', ['archive' => $archive])->assertCreated()->json();
        $restoredCampaign = (int) $restored['campaign']['id'];
        $restoredActor = Actor::where('campaign_id', $restoredCampaign)->where('name', 'Hero')->firstOrFail();
        $this->assertSame($newClass->id, $restoredActor->system['preparation']['classId']);
        $draft = EncounterBuilderDraft::where('campaign_id', $restoredCampaign)->firstOrFail();
        $this->assertSame($newCatalog->id, $draft->creatures[0]['catalogEntryId']);
        $journal = Journal::where('campaign_id', $restoredCampaign)->firstOrFail();
        $this->assertSame($newCatalog->id, $journal->catalog_entry_id);
        $this->assertSame($newCatalog->id, $journal->metadata['chapter']['id']);
        $this->assertSame('catalog:'.$newCatalog->id.':art', $journal->attachments[0]['ref']);
        $this->assertDatabaseHas('homebrew_packages', ['campaign_id' => $restoredCampaign, 'name' => 'Casa', 'enabled' => true]);
        $restoredRoll = RollTableRoll::whereHas('table', fn ($q) => $q->where('campaign_id', $restoredCampaign))->firstOrFail();
        $loot = LootResult::where('campaign_id', $restoredCampaign)->firstOrFail();
        $this->assertSame($restoredRoll->id, $loot->roll_table_roll_id);
        $this->assertSame($restoredActor->id, $loot->applied_actor_id);
    }

    public function test_v1_unmapped_catalog_ids_are_removed_instead_of_pointing_at_unrelated_rows(): void
    {
        $room = $this->room();
        $system = ActorStateFactory::character();
        $system['preparation'] = ['classId' => 999999, 'source' => 'PHB', 'edition' => '5e-2014', 'tasks' => []];
        $system['progression'] = ['classes' => [['classId' => 999999, 'name' => 'Wizard', 'level' => 1]], 'subclass' => ['subclassId' => 888888, 'name' => 'School', 'className' => 'Wizard']];
        $archive = [
            'format' => 'vtt-edson-campaign', 'version' => 1,
            'campaign' => ['name' => 'Antiga', 'ruleset' => '5e-2014'],
            'actors' => [['id' => 10, 'type' => 'character', 'name' => 'Hero', 'system' => $system]],
            'scenes' => [['id' => 20, 'name' => 'Cena', 'published' => true, 'state' => ['tokens' => [['id' => 't', 'name' => 'Hero', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => 10]], 'preparation' => ['entryId' => 777777, 'chapter' => 'Old', 'source' => 'BOOK', 'sourceHash' => 'x', 'reviewed' => true]]]],
            'journals' => [],
        ];
        $response = $this->postJson('/api/campaigns/import', ['archive' => $archive])->assertCreated()->json();
        $actor = Actor::where('campaign_id', $response['campaign']['id'])->firstOrFail();
        $this->assertArrayNotHasKey('preparation', $actor->system);
        $this->assertArrayNotHasKey('classId', $actor->system['progression']['classes'][0]);
        $this->assertArrayNotHasKey('subclassId', $actor->system['progression']['subclass']);
        $scene = Scene::where('campaign_id', $response['campaign']['id'])->firstOrFail();
        $this->assertArrayNotHasKey('preparation', $scene->state);
    }

    public function test_restore_rejects_public_non_image_media_and_leaves_no_campaign_or_files(): void
    {
        Storage::fake('public');
        $this->room();
        $before = Campaign::count();
        $archive = [
            'format' => 'vtt-edson-campaign', 'version' => 2,
            'campaign' => ['name' => 'Malicioso', 'ruleset' => '5e-2014'],
            'actors' => [],
            'scenes' => [['id' => 1, 'name' => 'Cena', 'state' => ['tokens' => []], 'background_path' => 'payload.php']],
            'journals' => [],
            'media' => [['path' => 'payload.php', 'sha256' => hash('sha256', '<?php echo 1;'), 'contentBase64' => base64_encode('<?php echo 1;')]],
        ];
        $this->postJson('/api/campaigns/import', ['archive' => $archive])->assertStatus(422);
        $this->assertSame($before, Campaign::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_database_failure_after_media_write_rolls_back_filesystem_too(): void
    {
        Storage::fake('public');
        $this->room();
        $before = Campaign::count();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $entry = ['kind' => 'monsters', 'name' => 'Same', 'slug' => 'same', 'version' => '1', 'data' => []];
        $archive = [
            'format' => 'vtt-edson-campaign', 'version' => 2,
            'campaign' => ['name' => 'Rollback', 'ruleset' => '5e-2014'],
            'actors' => [],
            'scenes' => [['id' => 1, 'name' => 'Cena', 'state' => ['tokens' => []], 'background_path' => 'background.png']],
            'journals' => [],
            'media' => [['path' => 'background.png', 'sha256' => hash('sha256', $png), 'contentBase64' => base64_encode($png)]],
            'homebrewPackages' => [[
                'name' => 'Broken', 'version' => '1', 'enabled' => true, 'entries' => [$entry, $entry],
            ]],
        ];

        $this->postJson('/api/campaigns/import', ['archive' => $archive])->assertStatus(500);
        $this->assertSame($before, Campaign::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_gm_can_export_and_restored_room_can_be_opened_immediately(): void
    {
        $room = $this->room();
        $gm = auth()->user();
        $campaignId = $room['campaign']['id'];
        $archive = $this->getJson('/api/campaigns/'.$campaignId.'/export')
            ->assertOk()
            ->assertHeader('content-disposition')
            ->json();

        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson('/api/campaigns/'.$campaignId.'/export')->assertForbidden();

        Sanctum::actingAs($gm);
        $restored = $this->postJson('/api/campaigns/import', ['archive' => $archive])
            ->assertCreated()
            ->assertJsonStructure(['campaign' => ['id'], 'room' => ['code'], 'sceneId'])
            ->json();
        $this->postJson('/api/rooms/join', ['code' => $restored['room']['code']])
            ->assertOk()
            ->assertJsonPath('scene.role', 'gm')
            ->assertJsonPath('campaign.id', $restored['campaign']['id']);
    }
}
