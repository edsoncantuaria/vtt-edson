<?php

namespace Tests\Feature;

use App\Models\CatalogEntry;
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
}
