<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActorTokenPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_sheets_and_hidden_tokens_are_not_exposed_by_read_or_write_responses(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Table'])->json();
        $cid = $room['campaign']['id'];
        $sid = $room['scene']['id'];
        $actor = $this->postJson('/api/campaigns/'.$cid.'/actors', ['name' => 'Secret', 'type' => 'monster'])->json('actor');
        $token = $this->postJson('/api/scenes/'.$sid.'/tokens', ['name' => 'Hidden', 'x' => 0, 'y' => 0, 'actorId' => $actor['id'], 'hidden' => true])->json('state.tokens.0');
        $this->postJson('/api/scenes/'.$sid.'/combat/start');
        $this->postJson('/api/scenes/'.$sid.'/combat/combatants', ['actorId' => $actor['id'], 'tokenId' => $token['id']]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertJsonCount(0, 'scene.state.tokens');
        $this->getJson('/api/campaigns/'.$cid.'/actors')->assertJsonCount(0, 'actors');
        $this->getJson('/api/actors/'.$actor['id'])->assertForbidden();
        $this->getJson('/api/actors/'.$actor['id'].'/export')->assertForbidden();
        $this->getJson('/api/campaigns/'.$cid.'/scenes')->assertJsonCount(0, 'scenes.0.state.tokens');
        $this->getJson('/api/scenes/'.$sid.'/combat')->assertJsonCount(0, 'combat.participants');
        $this->postJson('/api/scenes/'.$sid.'/chat', ['text' => 'Hello'])->assertJsonCount(0, 'state.tokens');
        Sanctum::actingAs($gm);
        $this->patchJson('/api/actors/'.$actor['id'], ['shared' => true])->assertOk();
        $this->postJson('/api/scenes/'.$sid.'/tokens', [...$token, 'hidden' => false])->assertOk();
        Sanctum::actingAs($player);
        $this->getJson('/api/actors/'.$actor['id'])->assertOk();
        $this->getJson('/api/scenes/'.$sid)->assertJsonCount(1, 'scene.state.tokens');
        $this->patchJson('/api/actors/'.$actor['id'], ['shared' => false])->assertForbidden();
    }

    public function test_action_rolls_attack_and_damage_without_losing_either_result(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Battle'])->json();
        $actor = $this->postJson('/api/campaigns/'.$room['campaign']['id'].'/actors', ['type' => 'character', 'name' => 'Hero', 'system' => ['actions' => [['id' => 'sword', 'name' => 'Sword', 'attackFormula' => '1d20+3', 'damageFormula' => '1d6+2']]]])->json('actor');
        $this->postJson('/api/scenes/'.$room['scene']['id'].'/actions', ['actorId' => $actor['id'], 'actionId' => 'sword'])->assertOk()->assertJsonCount(2, 'message.rolls')->assertJsonPath('message.rolls.0.kind', 'attack')->assertJsonPath('message.rolls.1.kind', 'damage');
    }
}
