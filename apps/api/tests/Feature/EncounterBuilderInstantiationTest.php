<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\Combat;
use App\Models\EncounterBuilderDraft;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\EncounterBuilderDifficulty;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EncounterBuilderInstantiationTest extends TestCase
{
    use RefreshDatabase;

    private function setupEncounter(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Arena', 'ruleset' => '5e-2014']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Arena', 'state' => SceneStateFactory::empty()]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $gm->id, 'role' => 'gm']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $player->id, 'role' => 'player']);
        $goblin = CatalogEntry::create([
            'slug' => 'goblin-mm', 'kind' => 'monsters', 'name' => 'Goblin', 'edition' => '5e-2014', 'source' => 'MM',
            'data' => [
                'raw' => [
                    'size' => ['S'], 'str' => 8, 'dex' => 14, 'con' => 10, 'int' => 10, 'wis' => 8, 'cha' => 8,
                    'hp' => ['average' => 7], 'ac' => [15], 'speed' => ['walk' => 30], 'cr' => '1/4',
                    'action' => [['name' => 'Scimitar', 'entries' => ['{@hit +4} to hit. {@damage 1d6 + 2} slashing damage.']]],
                ],
                'abilities' => ['str' => 8, 'dex' => 14, 'con' => 10, 'int' => 10, 'wis' => 8, 'cha' => 8],
                'hit_points' => 7, 'armor_class' => 15, 'speed' => ['walk' => 30], 'size' => 'S', 'type' => 'humanoid',
            ],
        ]);
        $creature = EncounterBuilderDifficulty::creature($goblin, 2);
        $draft = EncounterBuilderDraft::create([
            'campaign_id' => $campaign->id,
            'name' => 'Emboscada',
            'party' => [['level' => 1]],
            'creatures' => [$creature],
            'difficulty' => EncounterBuilderDifficulty::summarize([$creature], [['level' => 1]]),
            'metadata' => [],
        ]);

        return [$gm, $player, $campaign, $scene, $draft];
    }

    public function test_gm_instantiates_independent_monsters_tokens_and_starts_combat_idempotently(): void
    {
        [$gm, , $campaign, $scene, $draft] = $this->setupEncounter();
        Sanctum::actingAs($gm);
        $requestId = (string) Str::uuid();
        $url = '/api/encounter-drafts/'.$draft->id.'/instantiate';
        $payload = ['sceneId' => $scene->id, 'requestId' => $requestId, 'x' => 70, 'y' => 70, 'startCombat' => true];

        $response = $this->postJson($url, $payload)->assertCreated();
        $response->assertJsonCount(2, 'actors')->assertJsonCount(2, 'tokenIds')->assertJsonPath('existing', false);
        $this->assertSame(2, Actor::where('campaign_id', $campaign->id)->where('type', 'monster')->count());
        $actors = Actor::where('campaign_id', $campaign->id)->orderBy('id')->get();
        $this->assertNotSame($actors[0]->id, $actors[1]->id);
        $this->assertSame(7, $actors[0]->system['hp']['max']);
        $this->assertSame(15, $actors[0]->system['ac']);
        $this->assertSame('1d20+4', $actors[0]->system['actions'][0]['attackFormula']);
        $tokens = $scene->fresh()->state['tokens'];
        $this->assertCount(2, $tokens);
        $this->assertNotSame([$tokens[0]['x'], $tokens[0]['y']], [$tokens[1]['x'], $tokens[1]['y']]);
        $combat = Combat::where('scene_id', $scene->id)->where('is_active', true)->firstOrFail();
        $this->assertCount(2, $combat->participants);

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('existing', true);
        $this->assertSame(2, Actor::where('campaign_id', $campaign->id)->where('type', 'monster')->count());
        $this->assertCount(2, $scene->fresh()->state['tokens']);
    }

    public function test_instantiation_avoids_occupied_origin_and_can_add_to_existing_combat(): void
    {
        [$gm, , $campaign, $scene, $draft] = $this->setupEncounter();
        Sanctum::actingAs($gm);
        $state = $scene->state;
        $state['tokens'][] = ['id' => 'occupied', 'x' => 70, 'y' => 70, 'name' => 'Existing', 'ownerUserId' => $gm->id, 'size' => 1, 'hidden' => false, 'actorId' => null];
        $scene->update(['state' => $state]);
        $combat = Combat::create(['scene_id' => $scene->id, 'round' => 1, 'turn' => 0, 'is_active' => true]);

        $response = $this->postJson('/api/encounter-drafts/'.$draft->id.'/instantiate', [
            'sceneId' => $scene->id, 'requestId' => (string) Str::uuid(), 'x' => 70, 'y' => 70, 'addToCombat' => true,
        ])->assertCreated();
        $this->assertNotSame([70, 70], [$response->json('state.tokens.1.x'), $response->json('state.tokens.1.y')]);
        $this->assertSame(2, $combat->fresh()->participants()->count());
        $this->assertSame(2, Actor::where('campaign_id', $campaign->id)->count());
    }

    public function test_player_cannot_instantiate_and_missing_active_combat_rolls_back(): void
    {
        [$gm, $player, $campaign, $scene, $draft] = $this->setupEncounter();
        $url = '/api/encounter-drafts/'.$draft->id.'/instantiate';
        Sanctum::actingAs($player);
        $this->postJson($url, ['sceneId' => $scene->id, 'requestId' => (string) Str::uuid()])->assertForbidden();

        Sanctum::actingAs($gm);
        $this->postJson($url, ['sceneId' => $scene->id, 'requestId' => (string) Str::uuid(), 'addToCombat' => true])->assertUnprocessable();
        $this->assertSame(0, Actor::where('campaign_id', $campaign->id)->count());
        $this->assertCount(0, $scene->fresh()->state['tokens']);
    }
}
