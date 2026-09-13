<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActorTest extends TestCase
{
    use RefreshDatabase;

    private function campaignWithPlayer(User $gm, User $player): Campaign
    {
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Campanha de Teste']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Cena 1',
            'state' => SceneStateFactory::empty(),
        ]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $gm->id, 'role' => 'gm']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $player->id, 'role' => 'player']);

        return $campaign;
    }

    public function test_gm_can_create_npc(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);

        Sanctum::actingAs($gm, ['*']);
        $res = $this->postJson("/api/campaigns/{$campaign->id}/actors", [
            'type' => 'npc',
            'name' => 'Guarda da Cidade',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('actors', ['name' => 'Guarda da Cidade', 'type' => 'npc']);
    }

    public function test_player_cannot_create_npc(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);

        Sanctum::actingAs($player, ['*']);
        $res = $this->postJson("/api/campaigns/{$campaign->id}/actors", [
            'type' => 'npc',
            'name' => 'Trapaça',
        ]);

        $res->assertForbidden();
    }

    public function test_player_can_create_own_character_and_it_defaults_dnd_sheet(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);

        Sanctum::actingAs($player, ['*']);
        $res = $this->postJson("/api/campaigns/{$campaign->id}/actors", [
            'type' => 'character',
            'name' => 'Lyra',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('actor.system.abilities.str.score', 10);
        $res->assertJsonPath('actor.system.hp.max', 10);
        $this->assertIsObject(json_decode($res->getContent())->actor->system->spells->slots);
        $actorId = $res->json('actor.id');
        $export = $this->getJson("/api/actors/{$actorId}/export")->assertOk();
        $this->assertIsObject(json_decode($export->getContent())->system->spells->slots);
        $this->assertDatabaseHas('actors', ['name' => 'Lyra', 'owner_user_id' => $player->id]);
    }

    public function test_player_cannot_edit_another_players_character(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $other = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);
        SceneMember::create([
            'scene_id' => $campaign->scenes()->first()->id,
            'user_id' => $other->id,
            'role' => 'player',
        ]);

        Sanctum::actingAs($player, ['*']);
        $actor = $this->postJson("/api/campaigns/{$campaign->id}/actors", [
            'type' => 'character',
            'name' => 'Lyra',
        ])->json('actor');

        Sanctum::actingAs($other, ['*']);
        $res = $this->patchJson("/api/actors/{$actor['id']}", ['name' => 'Hackeado']);

        $res->assertForbidden();
    }

    public function test_gm_can_edit_any_actor_in_campaign(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);

        Sanctum::actingAs($player, ['*']);
        $actor = $this->postJson("/api/campaigns/{$campaign->id}/actors", [
            'type' => 'character',
            'name' => 'Lyra',
        ])->json('actor');

        Sanctum::actingAs($gm, ['*']);
        $res = $this->patchJson("/api/actors/{$actor['id']}", ['name' => 'Lyra, a Corajosa']);

        $res->assertOk();
        $this->assertDatabaseHas('actors', ['id' => $actor['id'], 'name' => 'Lyra, a Corajosa']);
    }

    public function test_multiclass_actor_accepts_one_subclass_per_class(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);
        $fighter = CatalogEntry::create(['slug' => 'fighter-phb', 'kind' => 'classes', 'name' => 'Fighter', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => []]);
        $wizard = CatalogEntry::create(['slug' => 'wizard-phb', 'kind' => 'classes', 'name' => 'Wizard', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => []]);
        $champion = CatalogEntry::create(['slug' => 'champion-phb', 'kind' => 'subclasses', 'name' => 'Champion', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => ['raw' => ['className' => 'Fighter', 'classSource' => 'PHB']]]);
        $evocation = CatalogEntry::create(['slug' => 'evocation-phb', 'kind' => 'subclasses', 'name' => 'Evocation', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => ['raw' => ['className' => 'Wizard', 'classSource' => 'PHB']]]);

        Sanctum::actingAs($player, ['*']);
        $actor = $this->postJson("/api/campaigns/{$campaign->id}/actors", ['type' => 'character', 'name' => 'Multiclasse'])->assertCreated()->json('actor');
        $system = $actor['system'];
        $system['bio']['level'] = 6;
        $system['progression'] = [
            'classes' => [
                ['classId' => $fighter->id, 'name' => 'Fighter', 'source' => 'PHB', 'level' => 3, 'hitDie' => 10],
                ['classId' => $wizard->id, 'name' => 'Wizard', 'source' => 'PHB', 'level' => 3, 'hitDie' => 6],
            ],
            'subclasses' => [
                ['subclassId' => $champion->id, 'name' => 'Champion', 'source' => 'PHB', 'className' => 'Fighter', 'classSource' => 'PHB'],
                ['subclassId' => $evocation->id, 'name' => 'Evocation', 'source' => 'PHB', 'className' => 'Wizard', 'classSource' => 'PHB'],
            ],
        ];

        $this->patchJson("/api/actors/{$actor['id']}", ['system' => $system, 'revision' => $actor['revision']])
            ->assertOk()
            ->assertJsonCount(2, 'actor.system.progression.subclasses');
    }

    public function test_outsider_cannot_see_campaign_actors(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $outsider = User::factory()->create();
        $campaign = $this->campaignWithPlayer($gm, $player);

        Sanctum::actingAs($outsider, ['*']);
        $res = $this->getJson("/api/campaigns/{$campaign->id}/actors");

        $res->assertForbidden();
    }
}
