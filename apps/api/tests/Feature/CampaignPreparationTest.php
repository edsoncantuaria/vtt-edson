<?php

namespace Tests\Feature;

use App\Models\CatalogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_filter_campaign_queries_and_only_gm_can_change_them(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        foreach (['PHB', 'TCE'] as $source) {
            CatalogEntry::create(['slug' => $source, 'kind' => 'spells', 'name' => 'Spell', 'source' => $source, 'edition' => '5e-2014', 'level' => 1, 'data' => []]);
        }
        $room = $this->postJson('/api/rooms', ['name' => 'Test'])->assertCreated()->json();
        $id = $room['campaign']['id'];
        $url = '/api/catalog/spells?campaignId='.$id;
        $this->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'PHB');
        $this->putJson('/api/campaigns/'.$id.'/catalog-sources', ['sources' => ['TCE']])->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'TCE');
        $this->putJson('/api/campaigns/'.$id.'/catalog-sources', ['sources' => null])->assertForbidden();
    }

    public function test_notes_are_private_until_shared_and_cannot_link_another_campaign_scene(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $a = $this->postJson('/api/rooms', ['name' => 'A'])->json();
        $b = $this->postJson('/api/rooms', ['name' => 'B'])->json();
        $url = '/api/campaigns/'.$a['campaign']['id'].'/journals';
        $this->postJson($url, ['title' => 'Secret', 'visibility' => 'gm', 'scene_id' => $b['scene']['id']])->assertUnprocessable();
        $note = $this->postJson($url, ['title' => 'Secret', 'visibility' => 'gm', 'scene_id' => $a['scene']['id']])->assertCreated()->json('entry');
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $a['room']['code']])->assertOk();
        $this->getJson($url)->assertJsonCount(0, 'entries');
        $this->patchJson('/api/journals/'.$note['id'], ['visibility' => 'all', 'updated_at' => $note['updated_at']])->assertForbidden();
        Sanctum::actingAs($gm);
        $this->patchJson('/api/journals/'.$note['id'], ['visibility' => 'all', 'updated_at' => $note['updated_at']])->assertOk();
        $this->patchJson('/api/journals/'.$note['id'], ['body' => 'Stale', 'updated_at' => $note['updated_at']])->assertConflict();
        Sanctum::actingAs($player);
        $this->getJson($url)->assertJsonCount(1, 'entries');
    }

    public function test_geometry_removal_is_gm_only_and_preserves_other_objects(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Map'])->json();
        $id = $room['scene']['id'];
        $this->postJson('/api/scenes/'.$id.'/walls', ['id' => 'wall', 'x1' => 0, 'y1' => 0, 'x2' => 10, 'y2' => 10])->assertOk();
        $this->postJson('/api/scenes/'.$id.'/doors', ['id' => 'door', 'x1' => 10, 'y1' => 10, 'x2' => 20, 'y2' => 20])->assertOk();
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']]);
        $this->deleteJson('/api/scenes/'.$id.'/geometry/walls/wall')->assertForbidden();
        Sanctum::actingAs($gm);
        $this->deleteJson('/api/scenes/'.$id.'/geometry/walls/wall')->assertOk()->assertJsonCount(0, 'state.walls')->assertJsonCount(1, 'state.doors');
    }
}
