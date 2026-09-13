<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CombatResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function setupBattle(string $ruleset = '5e-2024'): array
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Battle', 'ruleset' => $ruleset])->assertCreated()->json();
        $system = ActorStateFactory::character();
        $system['hp'] = ['value' => 50, 'max' => 50, 'temp' => 0];
        $system['spells']['slots'] = ['1' => ['max' => 2, 'used' => 0]];
        $system['actions'] = [['id' => 'fire', 'name' => 'Fire', 'kind' => 'spell', 'damageFormula' => '4d6', 'damageType' => 'fire', 'saveAbility' => 'dex', 'saveDc' => 15, 'saveEffect' => 'half', 'spellSlotLevel' => 1, 'concentration' => true]];
        $actor = $this->postJson('/api/campaigns/'.$room['campaign']['id'].'/actors', ['name' => 'Mage', 'type' => 'character', 'system' => $system])->assertCreated()->json('actor');

        return [$room, $actor, $gm, '/api/scenes/'.$room['scene']['id']];
    }

    private function action(string $base, int $actorId, int $damage = 23): string
    {
        $message = $this->postJson($base.'/actions', ['actorId' => $actorId, 'actionId' => 'fire'])->assertOk()->json('message');
        $message['rolls'][0]['total'] = $damage;
        DB::table('action_records')->where('message_id', $message['id'])->update(['message' => json_encode($message)]);

        return $message['id'];
    }

    public function test_save_is_persistent_private_idempotent_and_damage_requires_confirmation(): void
    {
        [$room, $caster, $gm, $base] = $this->setupBattle();
        $player = User::factory()->create();
        $target = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id, 'name' => 'Target', 'type' => 'character', 'system' => [...ActorStateFactory::character(), 'saves' => ['dex' => ['bonus' => 50]], 'hp' => ['value' => 30, 'max' => 30, 'temp' => 3], 'damageTraits' => ['resist' => ['fire'], 'vulnerable' => ['fire']]]]);
        $messageId = $this->action($base, $caster['id']);
        $path = $base.'/damage/'.$messageId;
        $this->postJson($path, ['actorId' => $target->id])->assertStatus(422);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson($path.'?actorId='.$caster['id'])->assertForbidden();
        $this->postJson($path.'/save', ['actorId' => $caster['id']])->assertForbidden();
        $save = $this->postJson($path.'/save', ['actorId' => $target->id, 'mode' => 'advantage'])->assertOk()->assertJsonPath('save.success', true)->json('save');
        $this->assertSame(30, $target->fresh()->system['hp']['value']);
        $this->postJson($path.'/save', ['actorId' => $target->id])->assertJsonPath('save', $save);
        $this->getJson($path.'?actorId='.$target->id)->assertOk()->assertJsonPath('preview.damage', 10)->assertJsonPath('save', $save);
        $this->postJson($path, ['actorId' => $target->id])->assertOk()->assertJsonPath('actor.system.hp.value', 23)->assertJsonPath('actor.system.hp.temp', 0);
        $this->postJson($path, ['actorId' => $target->id])->assertJsonPath('actor.system.hp.value', 23);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertJsonPath('actor.system.hp.value', 30)->assertJsonPath('actor.system.hp.temp', 3);
        // No stat block or saving throw payload is broadcast into public chat.
        $chat = Scene::findOrFail($room['scene']['id'])->state['chat'];
        $this->assertArrayNotHasKey('saves', $chat[0]);
        $this->assertCount(1, $chat);
        Sanctum::actingAs($gm);
        $this->getJson($path.'?actorId='.$target->id)->assertOk()->assertJsonPath('application.undone', true);
    }

    public function test_retry_consumes_once_and_undo_refunds_once_even_after_chat_expires(): void
    {
        [$room, $caster, , $base] = $this->setupBattle();
        $data = ['actorId' => $caster['id'], 'actionId' => 'fire', 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $data)->assertOk()->json('message');
        $this->postJson($base.'/actions', $data)->assertJsonPath('message.id', $message['id'])->assertJsonPath('actor.system.spells.slots.1.used', 1);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['chat'] = [];
        $scene->update(['state' => $state]);
        $this->getJson($base.'/damage/'.$message['id'].'?actorId='.$caster['id'])->assertOk();
        $path = $base.'/actions/'.$message['id'].'/undo';
        $this->postJson($path, ['actorId' => $caster['id']])->assertOk()->assertJsonPath('actor.system.spells.slots.1.used', 0)->assertJsonPath('actor.system.concentration', null);
        $this->postJson($path, ['actorId' => $caster['id']])->assertJsonPath('actor.system.spells.slots.1.used', 0);
        $this->postJson($base.'/actions', $data)->assertJsonPath('actor.system.spells.slots.1.used', 0);
        $this->postJson($base.'/damage/'.$message['id'], ['actorId' => $caster['id'], 'factor' => 1])->assertStatus(409);
    }

    public function test_generic_action_resource_is_consumed_once_and_undo_restores_it(): void
    {
        [, $caster, , $base] = $this->setupBattle();
        $actor = Actor::findOrFail($caster['id']);
        $system = $actor->system;
        $system['resources'] = [['id' => 'ki', 'name' => 'Ki', 'max' => 2, 'used' => 0, 'reset' => 'short']];
        $system['actions'][] = ['id' => 'flurry', 'name' => 'Rajada', 'kind' => 'attack', 'attackFormula' => '1d20+5', 'damageFormula' => '1d6+3', 'resourceId' => 'ki', 'resourceCost' => 1];
        $actor->update(['system' => $system]);

        $requestId = (string) Str::uuid();
        $data = ['actorId' => $actor->id, 'actionId' => 'flurry', 'requestId' => $requestId];
        $message = $this->postJson($base.'/actions', $data)->assertOk()
            ->assertJsonPath('actor.system.resources.0.used', 1)->json('message');
        $this->postJson($base.'/actions', $data)->assertOk()->assertJsonPath('actor.system.resources.0.used', 1);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $actor->id])->assertOk()
            ->assertJsonPath('actor.system.resources.0.used', 0);

        $actor = Actor::findOrFail($actor->id);
        $system = $actor->system;
        $system['resources'][0]['used'] = 2;
        $actor->update(['system' => $system]);
        $this->postJson($base.'/actions', ['actorId' => $actor->id, 'actionId' => 'flurry', 'requestId' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonPath('message', 'Não há usos suficientes de Ki.');
    }

    public function test_concentration_uses_damage_absorbed_by_temp_hp_and_undo_restores_effect(): void
    {
        [, $caster, , $base] = $this->setupBattle();
        $message = $this->action($base, $caster['id'], 81);
        $actor = Actor::findOrFail($caster['id']);
        $system = $actor->system;
        $system['hp']['temp'] = 100;
        $system['saves']['con'] = ['bonus' => -30];
        $actor->update(['system' => $system]);
        $path = $base.'/damage/'.$message;
        $data = ['actorId' => $actor->id];
        $this->postJson($path, [...$data, 'factor' => 1])->assertOk()->assertJsonPath('actor.system.hp.value', 50);
        $this->getJson($path.'?actorId='.$actor->id)->assertJsonPath('application.resolution.concentrationDc', 30);
        $save = $this->postJson($path.'/concentration', $data)->assertOk()->assertJsonPath('save.success', false)->assertJsonPath('actor.system.concentration', null)->json('save');
        $this->postJson($path.'/concentration', $data)->assertJsonPath('save', $save);
        $this->postJson($path, [...$data, 'undo' => true])->assertOk()->assertJsonPath('actor.system.hp.temp', 100)->assertJsonPath('actor.system.concentration.name', 'Fire');
    }

    public function test_revised_voluntary_failure_and_gm_success_are_authorized_by_edition(): void
    {
        [$room, $caster, , $base] = $this->setupBattle('5e-2014');
        $message = $this->action($base, $caster['id']);
        $player = User::factory()->create();
        Actor::findOrFail($caster['id'])->update(['owner_user_id' => $player->id]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']]);
        $path = $base.'/damage/'.$message.'/save';
        $data = ['actorId' => $caster['id'], 'decision' => 'failure', 'reason' => 'Aceito o efeito'];
        $this->postJson($path, $data)->assertForbidden();
        $this->postJson($path, [...$data, 'decision' => 'success'])->assertForbidden();
        DB::table('campaigns')->where('id', $room['campaign']['id'])->update(['ruleset' => '5e-2024']);
        $this->postJson($path, $data)->assertOk()->assertJsonPath('save.success', false);
    }

    public function test_stale_sheet_edit_cannot_erase_consumed_slot_and_zero_hp_ends_concentration(): void
    {
        [, $caster, , $base] = $this->setupBattle();
        $this->action($base, $caster['id']);
        $this->patchJson('/api/actors/'.$caster['id'], ['revision' => $caster['revision'], 'system' => $caster['system']])->assertStatus(409);
        $this->patchJson('/api/actors/'.$caster['id'], ['system' => $caster['system']])->assertStatus(422);
        $current = $this->getJson('/api/actors/'.$caster['id'])->json('actor');
        $current['system']['hp']['value'] = 0;
        $this->patchJson('/api/actors/'.$caster['id'], ['revision' => $current['revision'], 'system' => $current['system']])->assertOk()->assertJsonPath('actor.system.concentration', null);
    }
}
