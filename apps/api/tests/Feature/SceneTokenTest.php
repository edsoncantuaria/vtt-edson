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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SceneTokenTest extends TestCase
{
    use RefreshDatabase;

    private function sceneWithGmAndTwoPlayers(): array
    {
        $gm = User::factory()->create();
        $p1 = User::factory()->create();
        $p2 = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Campanha']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Cena', 'state' => SceneStateFactory::empty()]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $gm->id, 'role' => 'gm']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $p1->id, 'role' => 'player']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $p2->id, 'role' => 'player']);

        return [$gm, $p1, $p2, $campaign, $scene];
    }

    public function test_player_can_move_own_token_but_not_others(): void
    {
        [$gm, $p1, $p2, , $scene] = $this->sceneWithGmAndTwoPlayers();

        Sanctum::actingAs($gm, ['*']);
        $token = $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'x' => 10, 'y' => 10, 'ownerUserId' => $p1->id,
        ])->json('state.tokens.0');

        Sanctum::actingAs($p1, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'id' => $token['id'], 'x' => 50, 'y' => 60,
        ])->assertOk();

        Sanctum::actingAs($p2, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'id' => $token['id'], 'x' => 99, 'y' => 99,
        ])->assertForbidden();
    }

    public function test_token_movement_cannot_cross_walls_or_closed_doors(): void
    {
        [$gm, $p1, , , $scene] = $this->sceneWithGmAndTwoPlayers();
        Sanctum::actingAs($gm, ['*']);
        $token = $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'x' => 10, 'y' => 50, 'ownerUserId' => $p1->id,
        ])->json('state.tokens.0');
        $this->postJson("/api/scenes/{$scene->id}/walls", [
            'x1' => 50, 'y1' => 0, 'x2' => 50, 'y2' => 100,
        ])->assertOk();

        Sanctum::actingAs($p1, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'id' => $token['id'], 'x' => 90, 'y' => 50,
        ])->assertUnprocessable();

        Sanctum::actingAs($gm, ['*']);
        $fresh = $scene->fresh();
        $state = $fresh->state;
        $state['walls'] = [];
        $fresh->update(['state' => $state]);
        $door = $this->postJson("/api/scenes/{$scene->id}/doors", [
            'x1' => 50, 'y1' => 0, 'x2' => 50, 'y2' => 100, 'open' => false,
        ])->assertOk()->json('state.doors.0');

        Sanctum::actingAs($p1, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'id' => $token['id'], 'x' => 90, 'y' => 50,
        ])->assertUnprocessable();

        Sanctum::actingAs($gm, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/doors/{$door['id']}/toggle")->assertOk();
        Sanctum::actingAs($p1, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'id' => $token['id'], 'x' => 90, 'y' => 50,
        ])->assertOk();
    }

    public function test_locked_door_stays_closed_until_its_state_changes(): void
    {
        [$gm, , , , $scene] = $this->sceneWithGmAndTwoPlayers();
        Sanctum::actingAs($gm, ['*']);
        $door = $this->postJson("/api/scenes/{$scene->id}/doors", [
            'x1' => 20, 'y1' => 0, 'x2' => 20, 'y2' => 40,
            'open' => true, 'state' => 'locked',
        ])->assertOk()->json('state.doors.0');
        $this->assertFalse($door['open']);
        $this->assertSame('locked', $door['state']);
        $this->postJson("/api/scenes/{$scene->id}/doors/{$door['id']}/toggle")
            ->assertUnprocessable();
        $this->postJson("/api/scenes/{$scene->id}/doors", [
            ...$door, 'state' => 'normal', 'open' => false,
        ])->assertOk();
        $this->postJson("/api/scenes/{$scene->id}/doors/{$door['id']}/toggle")
            ->assertOk()
            ->assertJsonPath('state.doors.0.open', true);
    }

    public function test_only_gm_can_create_tokens(): void
    {
        [, $p1, , , $scene] = $this->sceneWithGmAndTwoPlayers();

        Sanctum::actingAs($p1, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", ['x' => 1, 'y' => 1])->assertForbidden();
    }

    public function test_gm_can_link_token_to_actor(): void
    {
        [$gm, , , $campaign, $scene] = $this->sceneWithGmAndTwoPlayers();
        $actor = Actor::create([
            'campaign_id' => $campaign->id,
            'owner_user_id' => $gm->id,
            'type' => 'monster',
            'name' => 'Goblin',
            'system' => ActorStateFactory::monster(),
        ]);

        Sanctum::actingAs($gm, ['*']);
        $res = $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'x' => 5, 'y' => 5, 'name' => 'Goblin', 'actorId' => $actor->id,
        ]);

        $res->assertOk();
        $this->assertSame($actor->id, $res->json('state.tokens.0.actorId'));
    }

    public function test_actor_id_must_exist(): void
    {
        [$gm, , , , $scene] = $this->sceneWithGmAndTwoPlayers();

        Sanctum::actingAs($gm, ['*']);
        $this->postJson("/api/scenes/{$scene->id}/tokens", [
            'x' => 5, 'y' => 5, 'actorId' => 99999,
        ])->assertStatus(422);
    }

    public function test_token_cannot_reference_an_actor_in_another_campaign(): void
    {
        [$gm, , , , $scene] = $this->sceneWithGmAndTwoPlayers();
        $other = Campaign::create(['owner_id' => $gm->id, 'name' => 'Other']);
        $actor = Actor::create(['campaign_id' => $other->id, 'type' => 'monster', 'name' => 'Secret', 'system' => ActorStateFactory::monster()]);
        Sanctum::actingAs($gm);
        $this->postJson('/api/scenes/'.$scene->id.'/tokens', ['x' => 1, 'y' => 1, 'actorId' => $actor->id])->assertUnprocessable();
    }

    public function test_portrait_upload_requires_ownership_and_returns_local_asset(): void
    {
        Storage::fake('public');
        [$gm, $p1, $p2, $campaign] = $this->sceneWithGmAndTwoPlayers();
        $actor = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => $p1->id, 'type' => 'character', 'name' => 'Hero', 'system' => ActorStateFactory::character()]);
        Sanctum::actingAs($p2);
        $this->postJson('/api/actors/'.$actor->id.'/image', ['image' => UploadedFile::fake()->createWithContent('portrait.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))])->assertForbidden();
        Sanctum::actingAs($p1);
        $result = $this->postJson('/api/actors/'.$actor->id.'/image', ['image' => UploadedFile::fake()->createWithContent('portrait.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))])->assertOk();
        Storage::disk('public')->assertExists($result->json('actor.imgPath'));
        $this->postJson('/api/actors/'.$actor->id.'/image', ['image' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])->assertUnprocessable();
    }
}
