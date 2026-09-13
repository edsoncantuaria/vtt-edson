<?php

namespace Tests\Feature;

use App\Models\CatalogEntry;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdventureImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_scoped_private_and_repeat_safe(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Test', 'library' => 'all'])->json();
        CatalogEntry::create(['slug' => 'goblin-mm', 'name' => 'goblin', 'kind' => 'monsters', 'source' => 'MM', 'edition' => '5e-2014', 'data' => ['raw' => ['cr' => '1/4', 'hp' => ['average' => 7], 'ac' => [15]]]]);
        $entry = CatalogEntry::create(['slug' => 'adventure', 'name' => 'Chapter', 'kind' => 'adventures', 'source' => 'LMoP', 'edition' => '5e-2014', 'data' => ['description' => 'Secret notes', 'bookName' => 'Lost Mine', 'raw' => ['entries' => [['name' => 'Goblin Den', 'entries' => ['Four {@creature goblin|MM|goblins} wait in {@area Goblin Den|A1}.']], ['type' => 'image', 'title' => 'Cave', 'imageType' => 'mapPlayer', 'href' => ['type' => 'internal', 'path' => 'adventure/LMoP/Cave.webp'], 'grid' => ['type' => 'square', 'size' => 150, 'offsetX' => -4], 'mapRegions' => [['id' => 'A1', 'name' => 'Goblin Den', 'points' => [[10, 20], [30, 40]], 'encounter' => ['monster' => 'goblin', 'count' => 4]]]]]]]]);
        $url = '/api/campaigns/'.$room['campaign']['id'].'/adventures/'.$entry->id;
        $this->getJson($url.'/preview')->assertOk()->assertJsonCount(1, 'maps')->assertJsonPath('book.name', 'Lost Mine')->assertJsonPath('maps.0.pins.0.label', 'Goblin Den')->assertJsonPath('maps.0.encounters.0.upstream.count', 4)->assertJsonPath('encounterHints.0.creature.name', 'goblin')->assertJsonPath('encounterHints.0.quantity', 4);
        $first = $this->postJson($url.'/import', ['maps' => [0]])->assertCreated()->json('scenes.0');
        $this->postJson($url.'/import', ['maps' => [0]])->assertCreated()->assertJsonPath('scenes.0.id', $first['id'])->assertJsonPath('scenes.0.existing', true);
        $this->getJson('/api/scenes/'.$first['id'])->assertJsonPath('scene.state.grid.size', 150)->assertJsonPath('scene.backgroundUrl', '/api/catalog-media/'.$entry->id.'/map-0');
        $this->assertDatabaseHas('journals', ['scene_id' => $first['id'], 'visibility' => 'gm']);
        $note = Journal::where('scene_id', $first['id'])->firstOrFail();
        $this->assertSame($entry->id, $note->catalog_entry_id);
        $this->assertSame('Aventuras/LMoP', $note->folder);
        $this->assertSame('Lost Mine', $note->metadata['book']['name']);
        $this->assertSame('Goblin Den', $note->metadata['pins'][0]['label']);
        $this->assertSame(4, $note->metadata['encounterHints'][0]['quantity']);
        $this->assertNotNull($note->metadata['encounterDraftId']);
        $this->assertDatabaseHas('encounter_builder_drafts', ['id' => $note->metadata['encounterDraftId'], 'campaign_id' => $room['campaign']['id']]);
        $this->assertSame('catalog:'.$entry->id.':map:0', $note->attachments[0]['ref']);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']]);
        $this->postJson($url.'/import', ['maps' => [0]])->assertForbidden();
        $this->getJson('/api/scenes/'.$first['id'])->assertForbidden();
    }
}
