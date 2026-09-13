<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CombatTest extends TestCase
{
    use RefreshDatabase;

    private function sceneWithGmAndPlayer(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Campanha']);
        $scene = Scene::create([
            'campaign_id' => $campaign->id,
            'name' => 'Masmorra',
            'state' => SceneStateFactory::empty(),
        ]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $gm->id, 'role' => 'gm']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $player->id, 'role' => 'player']);

        return [$gm, $player, $campaign, $scene];
    }

    public function test_gm_runs_full_combat_flow(): void
    {
        [$gm, , $campaign, $scene] = $this->sceneWithGmAndPlayer();

        $system = ActorStateFactory::character();
        $system['abilities']['dex']['score'] = 18; // +4

        $actor = Actor::create([
            'campaign_id' => $campaign->id,
            'owner_user_id' => $gm->id,
            'type' => 'character',
            'name' => 'Herói Veloz',
            'system' => $system,
        ]);

        Sanctum::actingAs($gm, ['*']);

        $this->postJson("/api/scenes/{$scene->id}/combat/start")->assertOk();

        $this->postJson("/api/scenes/{$scene->id}/combat/combatants", [
            'actorId' => $actor->id,
        ])->assertOk();
        $this->postJson("/api/scenes/{$scene->id}/combat/combatants", [
            'name' => 'Goblin',
        ])->assertOk();

        $res = $this->postJson("/api/scenes/{$scene->id}/combat/roll-initiative");
        $res->assertOk();
        $participants = $res->json('combat.participants');
        $this->assertCount(2, $participants);
        foreach ($participants as $p) {
            $this->assertIsInt($p['initiative']);
        }

        $current = $this->getJson("/api/scenes/{$scene->id}/combat")->json('combat');
        $this->assertSame(1, $current['round']);
        $this->assertSame(0, $current['turn']);

        // avança pelas duas posições e vira a rodada
        $this->postJson("/api/scenes/{$scene->id}/combat/next")->assertOk();
        $res = $this->postJson("/api/scenes/{$scene->id}/combat/next");
        $res->assertOk();
        $this->assertSame(2, $res->json('combat.round'));
        $this->assertSame(0, $res->json('combat.turn'));

        $res = $this->postJson("/api/scenes/{$scene->id}/combat/prev");
        $res->assertOk();
        $this->assertSame(1, $res->json('combat.round'));

        $this->postJson("/api/scenes/{$scene->id}/combat/end")->assertOk();
        $this->assertNull($this->getJson("/api/scenes/{$scene->id}/combat")->json('combat'));
    }

    public function test_player_cannot_control_combat(): void
    {
        [, $player, , $scene] = $this->sceneWithGmAndPlayer();

        Sanctum::actingAs($player, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/combat/start")->assertForbidden();
    }

    public function test_actions_without_active_combat_return_404(): void
    {
        [$gm, , , $scene] = $this->sceneWithGmAndPlayer();

        Sanctum::actingAs($gm, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/combat/next")->assertNotFound();
    }

    public function test_combat_rejects_foreign_actors_and_mismatched_tokens(): void
    {
        [$gm, , $campaign, $scene] = $this->sceneWithGmAndPlayer();
        Sanctum::actingAs($gm);
        $other = Campaign::create(['owner_id' => $gm->id, 'name' => 'Other']);
        $foreign = Actor::create(['campaign_id' => $other->id, 'name' => 'Secret', 'type' => 'monster', 'system' => ActorStateFactory::monster()]);
        $local = Actor::create(['campaign_id' => $campaign->id, 'name' => 'Hero', 'type' => 'character', 'system' => ActorStateFactory::character()]);
        $this->postJson('/api/scenes/'.$scene->id.'/combat/start')->assertOk();
        $url = '/api/scenes/'.$scene->id.'/combat/combatants';
        $this->postJson($url, ['actorId' => $foreign->id])->assertUnprocessable();
        $this->postJson($url, ['tokenId' => 'missing'])->assertUnprocessable();
        $token = $this->postJson('/api/scenes/'.$scene->id.'/tokens', ['x' => 0, 'y' => 0])->assertOk()->json('state.tokens.0');
        $this->postJson($url, ['actorId' => $local->id, 'tokenId' => $token['id']])->assertUnprocessable();
    }
}
