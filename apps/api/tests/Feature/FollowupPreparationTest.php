<?php

namespace Tests\Feature;

use App\Models\CatalogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FollowupPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_spell_filters_preserve_class_edition_and_campaign_sources(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Spells', 'ruleset' => '5e-2024'])->json();
        $class = CatalogEntry::create(['slug' => 'wizard', 'kind' => 'classes', 'name' => 'Wizard', 'source' => 'XPHB', 'edition' => '5e-2024', 'data' => []]);
        foreach ([['one', 'XPHB', 'A', true, ['XPHB:Wizard']], ['two', 'XPHB', 'V', false, ['XPHB:Wizard']], ['three', 'XPHB', 'A', true, ['XPHB:Druid']], ['four', 'TCE', 'A', true, ['XPHB:Wizard']]] as [$name, $source, $school, $ritual, $lists]) {
            CatalogEntry::create(['slug' => $name, 'kind' => 'spells', 'name' => $name, 'source' => $source, 'edition' => '5e-2024', 'level' => 1, 'data' => ['spellLists' => $lists, 'raw' => ['school' => $school, 'meta' => ['ritual' => $ritual]]]]);
        }
        $path = '/api/catalog/spells?campaignId='.$room['campaign']['id'].'&edition=5e-2024&classId='.$class->id.'&school=A&ritual=1&maxLevel=1';
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'one');
        $this->getJson(str_replace('5e-2024', '5e-2014', $path))->assertStatus(422);
    }

    public function test_imported_map_requires_review_and_catalog_refresh_preserves_preparation(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Book', 'library' => 'all'])->json();
        $entry = CatalogEntry::create(['slug' => 'chapter', 'kind' => 'adventures', 'name' => 'Cave', 'source' => 'LMoP', 'edition' => '5e-2014', 'data' => ['raw' => ['type' => 'image', 'imageType' => 'mapPlayer', 'title' => 'Cave', 'href' => ['type' => 'internal', 'path' => 'adventure/LMoP/cave.webp']]]]);
        $path = '/api/campaigns/'.$room['campaign']['id'].'/adventures/'.$entry->id;
        $scene = $this->postJson($path.'/import', ['maps' => [0]])->assertCreated()->json('scenes.0.id');
        $this->patchJson('/api/scenes/'.$scene, ['published' => true])->assertStatus(422);
        $before = $this->getJson('/api/scenes/'.$scene)->json('scene.state.preparation.sourceHash');
        $entry->update(['data' => [...$entry->data, 'description' => 'New text']]);
        $after = $this->getJson($path.'/preview')->json('sourceHash');
        $this->assertNotSame($before, $after);
        $this->postJson($path.'/import', ['maps' => [0]])->assertJsonPath('scenes.0.existing', true);
        $this->getJson('/api/scenes/'.$scene)->assertJsonPath('scene.state.preparation.sourceHash', $before);
        $this->patchJson('/api/scenes/'.$scene, ['preparationReviewed' => true, 'published' => true])->assertOk();
    }

    public function test_campaign_export_and_token_appearance_are_gm_only(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Backup'])->json();
        $path = '/api/scenes/'.$room['scene']['id'].'/tokens';
        $player = User::factory()->create();
        $token = $this->postJson($path, ['name' => 'Token', 'x' => 40, 'y' => 40, 'ownerUserId' => $player->id])->json('state.tokens.0');
        $appearance = ['border' => '#ffaa00', 'background' => '#112233', 'zoom' => 2, 'x' => .2, 'y' => -.1];
        $this->postJson($path, [...$token, 'size' => 2, 'appearance' => $appearance])->assertOk()->assertJsonPath('state.tokens.0.size', 2)->assertJsonPath('state.tokens.0.appearance.zoom', 2);
        $exportPath = '/api/campaigns/'.$room['campaign']['id'].'/export';
        $this->getJson($exportPath)->assertOk()->assertJsonPath('format', 'vtt-edson-campaign')->assertJsonCount(1, 'scenes')->assertJsonMissingPath('campaign.owner_id');
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']]);
        $this->getJson($exportPath)->assertForbidden();
        $this->postJson($path, [...$token, 'size' => 4, 'appearance' => [...$appearance, 'zoom' => 4]])->assertOk()->assertJsonPath('state.tokens.0.size', 2)->assertJsonPath('state.tokens.0.appearance.zoom', 2);
    }
}
