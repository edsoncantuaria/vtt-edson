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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SceneTokenTargetingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Targeting']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Visible map', 'published' => true, 'state' => SceneStateFactory::empty()]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $gm->id, 'role' => 'gm']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $player->id, 'role' => 'player']);
        $system = ActorStateFactory::character();
        $system['actions'] = [[
            'id' => 'bow', 'name' => 'Bow', 'kind' => 'attack', 'attackFormula' => '1d20+3',
            'damageFormula' => '1d6', 'target' => 'single', 'rangeFeet' => 30,
        ], [
            'id' => 'area', 'name' => 'Area effect', 'kind' => 'feature', 'target' => 'multiple',
            'maxTargets' => 2, 'rangeFeet' => 30, 'attackFormula' => '1d20+3',
        ]];
        $hero = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => $player->id, 'name' => 'Hero', 'type' => 'character', 'system' => $system]);
        $enemy = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => null, 'name' => 'Private goblin', 'type' => 'monster', 'system' => ActorStateFactory::monster()]);
        $enemy2 = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => null, 'name' => 'Private orc', 'type' => 'monster', 'system' => ActorStateFactory::monster()]);
        $state = $scene->state;
        $state['tokens'] = [
            ['id' => 'hero', 'name' => 'Hero', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $hero->id, 'ownerUserId' => $player->id],
            ['id' => 'enemy', 'name' => 'Goblin', 'x' => 70, 'y' => 0, 'size' => 1, 'actorId' => $enemy->id, 'ownerUserId' => null],
            ['id' => 'enemy2', 'name' => 'Orc', 'x' => 140, 'y' => 0, 'size' => 1, 'actorId' => $enemy2->id, 'ownerUserId' => null],
        ];
        $scene->update(['state' => $state]);

        return [$gm, $player, $scene, $hero, $enemy, $enemy2];
    }

    public function test_player_targets_visible_private_actor_by_token_handle_without_receiving_its_id_and_retry_is_stable(): void
    {
        [$gm, $player, $scene, $hero, $enemy] = $this->fixture();
        Sanctum::actingAs($player);
        $base = '/api/scenes/'.$scene->id;
        $this->getJson($base)->assertOk()->assertJsonPath('scene.state.tokens.1.actorId', null);
        $request = ['actorId' => $hero->id, 'actionId' => 'bow', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()
            ->assertJsonPath('message.targetActorIds', [])
            ->assertJsonPath('message.targetTokenIds', ['enemy'])->json('message');
        $stored = json_decode(DB::table('action_records')->first()->message, true);
        $this->assertSame([$enemy->id], $stored['targetActorIds']);
        $this->assertSame(['enemy'], $stored['targetTokenIds']);
        $this->assertDatabaseCount('roll_records', 2);
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id'])
            ->assertJsonPath('message.targetActorIds', []);
        $this->postJson($base.'/actions', [...$request, 'targetTokenIds' => ['enemy2']])->assertStatus(409);
        $this->assertDatabaseCount('action_records', 1);
        $this->assertDatabaseCount('roll_records', 2);
        $this->getJson($base)->assertOk()->assertJsonPath('scene.state.chat.0.targetActorIds', []);
        $this->getJson($base.'/actions')->assertOk()->assertJsonPath('messages.0.targetActorIds', []);

        // Visibility changes after a previous action cannot reveal the now-hidden handle in history.
        $state = $scene->fresh()->state;
        $state['tokens'][1]['hidden'] = true;
        $scene->update(['state' => $state]);
        $this->getJson($base)->assertOk()->assertJsonPath('scene.state.chat.0.targetTokenIds', []);
        $this->getJson($base.'/actions')->assertOk()->assertJsonPath('messages.0.targetTokenIds', []);
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id'])
            ->assertJsonPath('message.targetTokenIds', []);
        $this->postJson($base.'/actions', [
            'actorId' => $hero->id, 'actionId' => 'bow', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertDatabaseCount('roll_records', 2);
        Sanctum::actingAs($gm);
        $this->getJson($base.'/actions')->assertOk()->assertJsonPath('messages.0.targetActorIds', [$enemy->id]);
        $this->postJson($base.'/actions', [
            'actorId' => $hero->id, 'actionId' => 'bow', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('message.targetActorIds', [$enemy->id]);
    }

    public function test_server_rejects_forged_hidden_and_out_of_range_token_instances_even_if_another_copy_is_near(): void
    {
        [, $player, $scene, $hero, $enemy] = $this->fixture();
        $observer = User::factory()->create();
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $observer->id, 'role' => 'observer']);
        Sanctum::actingAs($observer);
        $this->postJson('/api/scenes/'.$scene->id.'/actions', [
            'actorId' => $hero->id, 'actionId' => 'bow', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid(),
        ])->assertForbidden();
        Sanctum::actingAs($player);
        $base = '/api/scenes/'.$scene->id.'/actions';
        $payload = ['actorId' => $hero->id, 'actionId' => 'bow', 'requestId' => (string) Str::uuid()];
        $this->postJson($base, [...$payload, 'targetTokenIds' => ['missing']])->assertForbidden();
        $this->postJson($base, [...$payload, 'targetTokenIds' => ['enemy', 'enemy']])->assertUnprocessable();
        $this->postJson($base, [...$payload, 'targetTokenIds' => ['enemy'], 'targetActorIds' => [$enemy->id]])->assertUnprocessable();
        $state = $scene->state;
        $state['tokens'][] = ['id' => 'far-copy', 'name' => 'Far', 'x' => 560, 'y' => 0, 'size' => 1, 'actorId' => $enemy->id, 'ownerUserId' => null];
        $state['tokens'][] = ['id' => 'unlinked', 'name' => 'Prop', 'x' => 70, 'y' => 70, 'size' => 1, 'actorId' => null, 'ownerUserId' => null];
        $scene->update(['state' => $state]);
        $this->postJson($base, [...$payload, 'targetTokenIds' => ['enemy', 'far-copy']])->assertUnprocessable();
        $this->postJson($base, [...$payload, 'targetTokenIds' => ['far-copy']])->assertUnprocessable();
        $this->postJson($base, [...$payload, 'targetTokenIds' => ['unlinked']])->assertUnprocessable();
        $this->assertDatabaseCount('action_records', 0);
        $this->assertDatabaseCount('roll_records', 0);
    }

    public function test_area_template_selection_accepts_multiple_visible_token_handles_and_enforces_maximum(): void
    {
        [, $player, $scene, $hero, $enemy, $enemy2] = $this->fixture();
        Sanctum::actingAs($player);
        $base = '/api/scenes/'.$scene->id.'/actions';
        $request = ['actorId' => $hero->id, 'actionId' => 'area', 'targetTokenIds' => ['enemy', 'enemy2'], 'requestId' => (string) Str::uuid()];
        $this->postJson($base, $request)->assertOk()->assertJsonPath('message.targetTokenIds', ['enemy', 'enemy2'])
            ->assertJsonPath('message.targetActorIds', []);
        $stored = json_decode(DB::table('action_records')->first()->message, true);
        $this->assertEqualsCanonicalizing([$enemy->id, $enemy2->id], $stored['targetActorIds']);
        $this->postJson($base, [...$request, 'requestId' => (string) Str::uuid(), 'targetTokenIds' => ['hero', 'enemy', 'enemy2']])->assertUnprocessable();
        $this->assertDatabaseCount('action_records', 1);
    }
}
