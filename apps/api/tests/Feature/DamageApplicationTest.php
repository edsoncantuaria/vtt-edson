<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DamageApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_damage_absorbs_temp_hp_is_idempotent_and_undo_detects_conflicts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Battle'])->json();
        $actor = $this->postJson('/api/campaigns/'.$room['campaign']['id'].'/actors', ['name' => 'Hero', 'type' => 'character', 'system' => ['hp' => ['value' => 20, 'max' => 20, 'temp' => 3], 'actions' => [['id' => 'hit', 'name' => 'Hit', 'damageFormula' => '1d6+9']]]])->json('actor');
        $base = '/api/scenes/'.$room['scene']['id'];
        $message = $this->postJson($base.'/actions', ['actorId' => $actor['id'], 'actionId' => 'hit'])->assertOk()->json('message.id');
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['chat'][0]['rolls'][0]['total'] = 10;
        $scene->update(['state' => $state]);
        DB::table('action_records')->where('message_id', $message)->update(['message' => json_encode($state['chat'][0])]);
        $path = $base.'/damage/'.$message;
        $data = ['actorId' => $actor['id'], 'factor' => 1];
        $this->postJson($path, $data)->assertOk()->assertJsonPath('actor.system.hp.value', 13)->assertJsonPath('actor.system.hp.temp', 0);
        $this->postJson($path, $data)->assertOk()->assertJsonPath('actor.system.hp.value', 13);
        $model = Actor::findOrFail($actor['id']);
        $system = $model->system;
        $system['hp']['value'] = 12;
        $model->update(['system' => $system]);
        $this->postJson($path, [...$data, 'undo' => true])->assertStatus(409);
        $system['hp']['value'] = 13;
        $model->update(['system' => $system]);
        $this->postJson($path, [...$data, 'undo' => true])->assertOk()->assertJsonPath('actor.system.hp.value', 20)->assertJsonPath('actor.system.hp.temp', 3);
        $this->postJson($path, $data)->assertOk()->assertJsonPath('actor.system.hp.value', 20);
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->postJson($path, $data)->assertForbidden();
    }

    public function test_cast_consumes_one_slot_and_refuses_exhausted_slots(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Magic'])->json();
        $actor = $this->postJson('/api/campaigns/'.$room['campaign']['id'].'/actors', ['name' => 'Mage', 'type' => 'character', 'system' => ['spells' => ['slots' => ['3' => ['max' => 1, 'used' => 0]]], 'actions' => [['id' => 'fire', 'name' => 'Fireball', 'damageFormula' => '8d6', 'spellSlotLevel' => 3]]]])->json('actor');
        $path = '/api/scenes/'.$room['scene']['id'].'/actions';
        $data = ['actorId' => $actor['id'], 'actionId' => 'fire'];
        $this->postJson($path, $data)->assertOk();
        $this->assertSame(1, Actor::findOrFail($actor['id'])->system['spells']['slots'][3]['used']);
        $this->postJson($path, $data)->assertStatus(422);
        $this->assertCount(1, Scene::findOrFail($room['scene']['id'])->state['chat']);
    }

    public function test_prepared_encounter_has_private_independent_actors_and_retry_is_idempotent(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Encounter'])->json();
        $actor = $this->postJson('/api/campaigns/'.$room['campaign']['id'].'/actors', ['name' => 'Goblin', 'type' => 'monster'])->json('actor');
        $path = '/api/scenes/'.$room['scene']['id'].'/encounters';
        $data = ['actorId' => $actor['id'], 'quantity' => 3, 'x' => 100, 'y' => 100, 'requestId' => (string) Str::uuid()];
        $tokens = $this->postJson($path, $data)->assertOk()->assertJsonCount(3, 'state.tokens')->assertJsonPath('state.tokens.0.hidden', true)->json('state.tokens');
        $this->assertCount(3, array_unique(array_column($tokens, 'actorId')));
        $this->postJson($path, $data)->assertOk()->assertJsonCount(3, 'state.tokens');
        $this->assertSame(4, Actor::count());
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertJsonCount(0, 'scene.state.tokens');
        $this->postJson($path, $data)->assertForbidden();
    }
}
