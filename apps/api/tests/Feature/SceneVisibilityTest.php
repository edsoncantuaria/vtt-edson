<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SceneVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_visibility_filters_api_join_and_combat_and_reveals_through_open_door(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Dungeon'])->json();
        $player = User::factory()->create();
        $actor = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id, 'name' => 'Scout', 'type' => 'character', 'system' => [...ActorStateFactory::character(), 'senses' => ['darkvision' => 60]]]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['vision']['dynamic'] = true;
        $state['tokens'] = [
            ['id' => 'scout', 'name' => 'Scout', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $actor->id, 'ownerUserId' => $player->id],
            ['id' => 'goblin', 'name' => 'Secret Goblin', 'x' => 300, 'y' => 0, 'size' => 1, 'ownerUserId' => null],
        ];
        $state['doors'] = [['id' => 'door', 'x1' => 150, 'y1' => -100, 'x2' => 150, 'y2' => 100, 'open' => false]];
        $scene->update(['state' => $state]);
        $base = '/api/scenes/'.$scene->id;
        $this->postJson($base.'/combat/start')->assertOk();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertJsonCount(1, 'scene.state.tokens');
        $this->getJson($base)->assertJsonCount(1, 'scene.state.tokens');
        $this->getJson($base.'/combat')->assertDontSee('Secret Goblin');
        $state['doors'][0]['open'] = true;
        $scene->update(['state' => $state]);
        $this->getJson($base)->assertJsonCount(2, 'scene.state.tokens');
        $state['tokens'][1]['hidden'] = true;
        $scene->update(['state' => $state]);
        $this->getJson($base)->assertJsonCount(1, 'scene.state.tokens');
        $state['tokens'][1]['hidden'] = false;
        $state['tokens'][1]['x'] = 1000;
        $scene->update(['state' => $state]);
        $this->getJson($base)->assertJsonCount(1, 'scene.state.tokens');
    }

    public function test_secret_closed_door_is_exposed_to_players_as_a_wall(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Secrets'])->json();
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $scene = Scene::findOrFail($room['scene']['id']);
        Sanctum::actingAs($gm);
        $door = $this->postJson('/api/scenes/'.$scene->id.'/doors', [
            'x1' => 100, 'y1' => 0, 'x2' => 100, 'y2' => 100,
            'open' => false, 'state' => 'secret',
        ])->assertOk()->json('state.doors.0');

        Sanctum::actingAs($player);
        $payload = $this->getJson('/api/scenes/'.$scene->id)->assertOk()->json('scene.state');
        $this->assertCount(0, $payload['doors']);
        $this->assertTrue(collect($payload['walls'])->contains(fn ($wall) => $wall['id'] === 'secret-door-'.$door['id']));
    }

    public function test_passive_perception_detects_only_hidden_tokens_with_an_explicit_stealth_dc(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Stealth'])->json();
        $player = User::factory()->create();
        $system = ActorStateFactory::character();
        $system['abilities']['wis']['score'] = 14;
        $system['skills']['perception'] = ['proficient' => true, 'expertise' => false];
        $actor = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id, 'type' => 'character', 'name' => 'Scout', 'system' => $system]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['vision'] = ['dynamic' => true, 'darkness' => false, 'normalVisionFeet' => 60];
        $state['tokens'] = [
            ['id' => 'scout', 'name' => 'Scout', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $actor->id, 'ownerUserId' => $player->id],
            ['id' => 'easy', 'name' => 'Easy', 'x' => 200, 'y' => 0, 'size' => 1, 'ownerUserId' => null, 'hidden' => true, 'stealthDc' => 14],
            ['id' => 'hard', 'name' => 'Hard', 'x' => 250, 'y' => 0, 'size' => 1, 'ownerUserId' => null, 'hidden' => true, 'stealthDc' => 15],
            ['id' => 'absolute', 'name' => 'Absolute', 'x' => 300, 'y' => 0, 'size' => 1, 'ownerUserId' => null, 'hidden' => true],
        ];
        $scene->update(['state' => $state]);

        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $payload = $this->getJson('/api/scenes/'.$scene->id)->assertOk()->json('scene.state.tokens');
        $this->assertSame(['scout', 'easy'], array_column($payload, 'id'));
        $this->assertTrue(collect($payload)->firstWhere('id', 'easy')['detected']);
    }

    public function test_passive_perception_detects_secret_door_only_when_it_is_in_sight(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Secret door'])->json();
        $player = User::factory()->create();
        $system = ActorStateFactory::character();
        $system['abilities']['wis']['score'] = 14;
        $system['skills']['perception'] = ['proficient' => true, 'expertise' => false];
        $actor = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id, 'type' => 'character', 'name' => 'Scout', 'system' => $system]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['vision'] = ['dynamic' => true, 'darkness' => false, 'normalVisionFeet' => 60];
        $state['tokens'] = [['id' => 'scout', 'name' => 'Scout', 'x' => 0, 'y' => 50, 'size' => 1, 'actorId' => $actor->id, 'ownerUserId' => $player->id]];
        $state['doors'] = [['id' => 'secret', 'x1' => 200, 'y1' => 0, 'x2' => 200, 'y2' => 100, 'open' => false, 'state' => 'secret', 'perceptionDc' => 14]];
        $scene->update(['state' => $state]);

        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $visible = $this->getJson('/api/scenes/'.$scene->id)->assertOk()->json('scene.state');
        $this->assertCount(1, $visible['doors']);
        $this->assertSame('secret', $visible['doors'][0]['state']);
        $this->assertTrue($visible['doors'][0]['detected']);

        $state['walls'] = [['id' => 'screen', 'x1' => 100, 'y1' => 0, 'x2' => 100, 'y2' => 100]];
        $scene->update(['state' => $state]);
        $blocked = $this->getJson('/api/scenes/'.$scene->id)->assertOk()->json('scene.state');
        $this->assertCount(0, $blocked['doors']);
        $this->assertTrue(collect($blocked['walls'])->contains(fn ($wall) => $wall['id'] === 'secret-door-secret'));
    }

    public function test_darkness_requires_darkvision_or_a_visible_light_source(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Darkness'])->json();
        $player = User::factory()->create();
        $actor = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id, 'type' => 'character', 'name' => 'Human', 'system' => ActorStateFactory::character()]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['vision'] = ['dynamic' => true, 'darkness' => true, 'normalVisionFeet' => 60];
        $state['tokens'] = [
            ['id' => 'human', 'name' => 'Human', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $actor->id, 'ownerUserId' => $player->id],
            ['id' => 'target', 'name' => 'Target', 'x' => 200, 'y' => 0, 'size' => 1, 'ownerUserId' => null],
        ];
        $scene->update(['state' => $state]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson('/api/scenes/'.$scene->id)->assertJsonCount(1, 'scene.state.tokens');

        $state['lights'] = [['id' => 'torch', 'x' => 200, 'y' => 0, 'radius' => 100]];
        $scene->update(['state' => $state]);
        $this->getJson('/api/scenes/'.$scene->id)->assertJsonCount(2, 'scene.state.tokens');

        $state['lights'] = [];
        $scene->update(['state' => $state]);
        $system = $actor->system;
        $system['senses']['darkvision'] = 60;
        $actor->update(['system' => $system]);
        $this->getJson('/api/scenes/'.$scene->id)->assertJsonCount(2, 'scene.state.tokens');
    }
}
