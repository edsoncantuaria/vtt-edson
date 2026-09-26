<?php

namespace Tests\Feature;

use App\Models\ActorDocument;
use App\Models\CatalogEntry;
use App\Models\HomebrewEntry;
use App\Models\HomebrewPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_separates_editions_and_sources_without_overwriting_names(): void
    {
        foreach (['5e-2014' => 'PHB', '5e-2024' => 'XPHB'] as $edition => $source) {
            CatalogEntry::create(['slug' => $source, 'kind' => 'spells', 'name' => 'Fireball', 'edition' => $edition, 'source' => $source, 'level' => 3, 'data' => ['description' => 'Spell']]);
        }
        CatalogEntry::create(['slug' => 'foundry-override', 'kind' => 'spells', 'name' => 'Fireball', 'edition' => '5e-2024', 'source' => 'XPHB', 'level' => null, 'data' => ['raw' => ['activities' => []]]]);
        $this->getJson('/api/catalog/spells')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/catalog/spells?edition=5e-2024&query=Fire&level=3')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'XPHB')->assertJsonPath('sources.0', 'XPHB');
        $this->getJson('/api/catalog/spells?source=PHB')->assertJsonPath('data.0.edition', '5e-2014');
        $this->getJson('/api/catalog/spells?perPage=0')->assertUnprocessable();
        $this->getJson('/api/catalog/secrets')->assertNotFound();
    }

    public function test_reading_requires_campaign_membership_and_respects_sharing(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Library'])->assertCreated()->json();
        $url = '/api/catalog/adventures?campaignId='.$room['campaign']['id'];
        $this->getJson($url)->assertOk();
        $this->getJson('/api/catalog/books')->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url)->assertForbidden();
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $chapter = CatalogEntry::create(['slug' => 'chapter', 'kind' => 'adventures', 'name' => 'Secret', 'edition' => '5e-2014', 'source' => 'LMoP', 'data' => []]);
        $phb = CatalogEntry::create(['slug' => 'phb', 'kind' => 'books', 'name' => 'Player chapter', 'edition' => '5e-2014', 'source' => 'PHB', 'data' => []]);
        $shareUrl = '/api/campaigns/'.$room['campaign']['id'].'/catalog/'.$chapter->id.'/share';
        $this->putJson($shareUrl, ['shared' => true])->assertForbidden();
        $this->getJson('/api/catalog/books?campaignId='.$room['campaign']['id'])->assertJsonPath('data.0.id', $phb->id);
        Sanctum::actingAs($gm);
        $this->putJson($shareUrl, ['shared' => true])->assertOk();
        $this->putJson($shareUrl, ['shared' => true])->assertOk();
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson($url)->assertJsonPath('data.0.id', $chapter->id);
        Sanctum::actingAs($gm);
        $this->putJson($shareUrl, ['shared' => false])->assertOk();
        Sanctum::actingAs($player);
        $this->getJson($url)->assertJsonCount(0, 'data');
    }

    public function test_one_search_covers_all_core_categories_without_revealing_private_adventures(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Library', 'library' => 'all'])->assertCreated()->json();
        $cid = $room['campaign']['id'];
        $kinds = ['monsters', 'spells', 'items', 'classes', 'races', 'backgrounds', 'feats', 'adventures'];
        foreach ($kinds as $kind) {
            CatalogEntry::create([
                'slug' => 'search-'.$kind, 'kind' => $kind, 'name' => 'Shared query '.$kind,
                'source' => 'PHB', 'edition' => '5e-2014', 'level' => $kind === 'spells' ? 1 : null,
                'data' => ['sourceName' => 'Player Handbook'],
            ]);
        }
        $url = '/api/catalog/all?campaignId='.$cid.'&query=Shared%20query&edition=5e-2014';
        $this->getJson($url)->assertOk()->assertJsonCount(8, 'data');
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/catalog/all?campaignId='.$cid.'&edition=5e-2024')->assertJsonCount(0, 'data');
        $adventure = CatalogEntry::where('kind', 'adventures')->firstOrFail();
        Sanctum::actingAs($gm);
        $this->putJson('/api/campaigns/'.$cid.'/catalog/'.$adventure->id.'/share', ['shared' => true])->assertOk();
        Sanctum::actingAs($player);
        $this->getJson($url)->assertOk()->assertJsonCount(8, 'data');
    }

    public function test_source_names_and_homebrew_respect_campaign_edition(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Library', 'library' => 'all'])->assertCreated()->json();
        $cid = $room['campaign']['id'];
        CatalogEntry::create(['slug' => 'source-phb', 'kind' => 'items', 'name' => 'Rope', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => ['sourceName' => 'Player Handbook']]);
        CatalogEntry::create(['slug' => 'source-xphb', 'kind' => 'items', 'name' => 'Rope', 'source' => 'XPHB', 'edition' => '5e-2024', 'data' => ['sourceName' => 'Player Handbook 2024']]);
        $this->getJson('/api/campaigns/'.$cid.'/catalog-sources')->assertOk()
            ->assertJsonPath('sourceNames.PHB', 'Player Handbook')->assertJsonMissingPath('sourceNames.XPHB');
        $this->putJson('/api/campaigns/'.$cid.'/catalog-sources', ['sources' => ['XPHB']])->assertUnprocessable();
        $package = HomebrewPackage::create(['campaign_id' => $cid, 'name' => 'House', 'version' => '1', 'enabled' => true]);
        HomebrewEntry::create(['homebrew_package_id' => $package->id, 'kind' => 'spells', 'name' => 'Magic', 'slug' => 'magic', 'version' => '1', 'data' => []]);
        $this->getJson('/api/catalog/all?campaignId='.$cid.'&edition=5e-2014')->assertJsonCount(1, 'homebrew');
        $this->getJson('/api/catalog/all?campaignId='.$cid.'&edition=5e-2014&scope=homebrew')
            ->assertJsonCount(0, 'data')->assertJsonCount(1, 'homebrew');
        $this->getJson('/api/catalog/all?campaignId='.$cid.'&edition=5e-2024')->assertJsonCount(0, 'homebrew');
    }

    public function test_materialized_content_preserves_provenance_and_rejects_other_editions_and_disabled_sources(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Library'])->assertCreated()->json();
        $campaignId = $room['campaign']['id'];
        $actor = $this->postJson('/api/campaigns/'.$campaignId.'/actors', ['type' => 'character', 'name' => 'Hero'])->assertCreated()->json('actor');
        $first = CatalogEntry::create([
            'slug' => 'potion-phb', 'kind' => 'items', 'name' => 'Potion', 'source' => 'PHB',
            'edition' => '5e-2014', 'content_hash' => str_repeat('a', 64),
            'data' => ['sourceName' => 'Player Handbook', 'description' => 'Original'],
        ]);
        $otherEdition = CatalogEntry::create(['slug' => 'potion-xphb', 'kind' => 'items', 'name' => 'Potion', 'source' => 'XPHB', 'edition' => '5e-2024', 'data' => []]);
        $disabled = CatalogEntry::create(['slug' => 'potion-tce', 'kind' => 'items', 'name' => 'Potion', 'source' => 'TCE', 'edition' => '5e-2014', 'data' => []]);
        $path = '/api/actors/'.$actor['id'].'/documents';
        $this->getJson('/api/catalog/all?campaignId='.$campaignId.'&scope=campaign')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($path, ['catalogEntryId' => $otherEdition->id, 'kind' => 'item'])->assertUnprocessable();
        $this->postJson($path, ['catalogEntryId' => $disabled->id, 'kind' => 'item'])->assertUnprocessable();
        $created = $this->postJson($path, ['catalogEntryId' => $first->id, 'kind' => 'item'])->assertCreated()
            ->assertJsonPath('document.data.origin.catalogEntryId', $first->id)
            ->assertJsonPath('document.data.origin.edition', '5e-2014')
            ->assertJsonPath('document.data.origin.sourceName', 'Player Handbook')
            ->assertJsonPath('document.data.origin.contentHash', str_repeat('a', 64))
            ->json('document');
        $this->getJson('/api/catalog/all?campaignId='.$campaignId.'&scope=campaign')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.inCampaign', true)
            ->assertJsonCount(0, 'homebrew');
        $this->getJson('/api/catalog/all?campaignId='.$campaignId.'&scope=library')->assertOk()
            ->assertJsonPath('data.0.inCampaign', true)->assertJsonCount(0, 'homebrew');
        $first->update(['content_hash' => str_repeat('b', 64), 'data' => ['description' => 'Revised']]);
        $document = ActorDocument::findOrFail($created['id']);
        $this->assertSame('Original', $document->data['description']);
        $this->assertSame(str_repeat('a', 64), $document->data['origin']['contentHash']);
    }
}
