<?php

namespace Tests\Feature;

use App\Game\Dice\DiceRoller;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function table(array $actions): array
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Action pipeline'])->assertCreated()->json();
        $system = ActorStateFactory::character();
        $system['spells']['slots'] = ['1' => ['max' => 1, 'used' => 0]];
        $system['resources'] = [['id' => 'ki', 'name' => 'Ki', 'max' => 2, 'used' => 0, 'reset' => 'short']];
        $system['actions'] = $actions;
        $caster = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $gm->id, 'name' => 'Caster', 'type' => 'character', 'system' => $system]);
        $targetSystem = ActorStateFactory::character();
        $targetSystem['hp'] = ['value' => 3, 'max' => 12, 'temp' => 0];
        $target = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $gm->id, 'name' => 'Target', 'type' => 'character', 'system' => $targetSystem]);

        return [$gm, $room, $caster, $target, '/api/scenes/'.$room['scene']['id']];
    }

    public function test_composed_attack_damage_heal_has_persisted_steps_and_requires_audited_healing_confirmation(): void
    {
        [$gm, $room, $caster, $target, $base] = $this->table([[
            'id' => 'hybrid', 'name' => 'Golpe restaurador', 'kind' => 'spell',
            'attackFormula' => '1d20+5', 'damageFormula' => '1d6+2', 'healingFormula' => '1d4+3',
            'spellSlotLevel' => 1, 'target' => 'single', 'economy' => 'action', 'damageType' => 'radiant',
        ]]);
        $request = ['actorId' => $caster->id, 'actionId' => 'hybrid', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()->assertJsonCount(3, 'message.rolls')
            ->assertJsonPath('actor.system.spells.slots.1.used', 1)->assertJsonPath('message.economy', 'action')->json('message');
        $this->assertSame(['attack', 'damage', 'heal'], array_column($message['pipeline'], 'kind'));
        $this->assertEqualsCanonicalizing(array_column($message['rolls'], 'id'), array_column($message['pipeline'], 'rollId'));
        $this->assertDatabaseCount('roll_records', 3);
        $this->assertDatabaseHas('roll_records', ['id' => $message['rolls'][2]['id'], 'context' => 'heal', 'step' => 'heal']);
        $this->assertSame(3, $target->fresh()->system['hp']['value']);
        $path = $base.'/actions/'.$message['id'].'/heal';
        $this->getJson($path.'?actorId='.$target->id)->assertOk()->assertJsonPath('application', null);
        $this->postJson($path, ['actorId' => $target->id])->assertUnprocessable();
        $this->assertSame(3, $target->fresh()->system['hp']['value']);
        $first = $this->postJson($path, ['actorId' => $target->id, 'confirmed' => true])->assertOk()
            ->assertJsonPath('application.confirmedBy', $gm->id)->assertJsonPath('application.undone', false);
        $after = $target->fresh()->system['hp']['value'];
        $this->assertGreaterThan(3, $after);
        $this->postJson($path, ['actorId' => $target->id, 'confirmed' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', $after);
        $this->assertDatabaseCount('action_healing_applications', 1);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $caster->id])->assertStatus(409);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 3)->assertJsonPath('application.undone', true);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $caster->id])->assertOk();
        $this->postJson($path, ['actorId' => $target->id, 'confirmed' => true])->assertStatus(409);
        $this->assertSame($first->json('application.before.value'), $target->fresh()->system['hp']['value']);
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->assertDatabaseCount('roll_records', 3);
    }

    public function test_no_roll_effect_only_action_is_audited_and_refunded_once(): void
    {
        [, $room, $caster, $target, $base] = $this->table([[
            'id' => 'ward', 'name' => 'Proteção', 'kind' => 'feature', 'target' => 'single',
            'resourceId' => 'ki', 'resourceCost' => 1,
            'effect' => ['name' => 'Guard', 'target' => 'targets', 'trigger' => 'on-use',
                'duration' => ['unit' => 'rounds', 'remaining' => 1], 'modifiers' => [], 'conditions' => ['guarded']],
        ]]);
        $request = ['actorId' => $caster->id, 'actionId' => 'ward', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()->assertJsonCount(0, 'message.rolls')
            ->assertJsonPath('message.actionKind', 'feature')->assertJsonPath('actor.system.resources.0.used', 1)->json('message');
        $this->assertDatabaseHas('active_effects', ['actor_id' => $target->id, 'name' => 'Guard']);
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->assertSame(1, ActiveEffect::where('actor_id', $target->id)->count());
        $this->assertDatabaseCount('roll_records', 0);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $caster->id])->assertOk()->assertJsonPath('actor.system.resources.0.used', 0);
        $this->assertSame(0, ActiveEffect::where('actor_id', $target->id)->count());
    }

    public function test_reusing_a_key_with_changed_action_or_targets_is_rejected_and_invalid_later_step_rolls_back(): void
    {
        [, $room, $caster, $target, $base] = $this->table([
            ['id' => 'sword', 'name' => 'Sword', 'kind' => 'attack', 'attackFormula' => '1d20+2', 'damageFormula' => '1d6+1'],
            ['id' => 'bolt', 'name' => 'Bolt', 'kind' => 'spell', 'attackFormula' => '1d20+2', 'damageFormula' => '1d8+1'],
            ['id' => 'broken', 'name' => 'Broken', 'kind' => 'spell', 'attackFormula' => '1d20+2', 'healingFormula' => 'INVALID', 'spellSlotLevel' => 1],
        ]);
        $requestId = (string) Str::uuid();
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'sword', 'requestId' => $requestId])->assertOk();
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'bolt', 'requestId' => $requestId])->assertStatus(409);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'sword', 'targetActorIds' => [$target->id], 'requestId' => $requestId])->assertStatus(409);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'broken', 'requestId' => (string) Str::uuid()])->assertStatus(422);
        $this->assertDatabaseCount('action_records', 1);
        $this->assertDatabaseCount('roll_records', 2);
        $this->assertSame(0, $caster->fresh()->system['spells']['slots'][1]['used']);
    }

    public function test_target_count_and_range_are_authoritative_and_save_only_actions_are_supported(): void
    {
        [, $room, $caster, $target, $base] = $this->table([
            ['id' => 'bow', 'name' => 'Bow', 'kind' => 'attack', 'attackFormula' => '1d20+4', 'target' => 'single', 'rangeFeet' => 30],
            ['id' => 'save', 'name' => 'Fear', 'kind' => 'spell', 'saveAbility' => 'wis', 'saveEffect' => 'none', 'target' => 'single'],
        ]);
        $bow = ['actorId' => $caster->id, 'actionId' => 'bow', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()];
        $this->postJson($base.'/actions', $bow)->assertStatus(422);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['tokens'] = [
            ['id' => 'a', 'name' => 'Caster', 'x' => 0, 'y' => 0, 'actorId' => $caster->id],
            ['id' => 'b', 'name' => 'Target', 'x' => 490, 'y' => 0, 'actorId' => $target->id],
        ];
        $scene->update(['state' => $state]);
        $this->postJson($base.'/actions', $bow)->assertStatus(422);
        $state['tokens'][1]['x'] = 350;
        $scene->update(['state' => $state]);
        $this->postJson($base.'/actions', $bow)->assertOk()->assertJsonCount(1, 'message.rolls');
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'save', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('message.save.ability', 'wis')->assertJsonCount(0, 'message.rolls');
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'save', 'targetActorIds' => [], 'requestId' => (string) Str::uuid()])->assertStatus(422);
    }

    public function test_non_owner_cannot_execute_or_confirm_someone_elses_healing(): void
    {
        [, $room, $caster, $target, $base] = $this->table([[
            'id' => 'heal', 'name' => 'Heal', 'kind' => 'spell', 'healingFormula' => '1d4+3', 'target' => 'single',
        ]]);
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'heal', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'heal', 'requestId' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($base.'/actions/'.$message['id'].'/heal', ['actorId' => $target->id, 'confirmed' => true])->assertForbidden();
        $this->assertSame(3, $target->fresh()->system['hp']['value']);
    }

    public function test_attack_only_applies_on_hit_condition_after_target_confirmation_without_damage(): void
    {
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => min($max, 15)));
        [, , $caster, $target, $base] = $this->table([[
            'id' => 'mark', 'name' => 'Mark', 'kind' => 'attack', 'attackFormula' => '1d20+5', 'target' => 'single',
            'effect' => ['name' => 'Marked', 'target' => 'targets', 'trigger' => 'on-hit',
                'duration' => ['unit' => 'rounds', 'remaining' => 2], 'modifiers' => [], 'conditions' => ['marked']],
        ]]);
        $request = ['actorId' => $caster->id, 'actionId' => 'mark', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()->assertJsonCount(1, 'message.rolls')->json('message');
        $this->assertDatabaseCount('active_effects', 0);
        $path = $base.'/damage/'.$message['id'];
        $this->getJson($path.'?actorId='.$target->id)->assertOk()->assertJsonPath('preview.hit', true)->assertJsonPath('preview.damage', 0);
        $this->postJson($path, ['actorId' => $target->id])->assertOk();
        $this->assertDatabaseHas('active_effects', ['actor_id' => $target->id, 'name' => 'Marked']);
        $this->postJson($path, ['actorId' => $target->id, 'correct' => true, 'requestId' => (string) Str::uuid(),
            'damageOverride' => 0, 'reason' => 'Condição já aplicada'])->assertStatus(409);
        $this->postJson($path, ['actorId' => $target->id])->assertOk();
        $this->assertSame(1, ActiveEffect::where('actor_id', $target->id)->count());
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk();
        $this->assertDatabaseCount('active_effects', 0);
    }

    public function test_save_only_can_apply_a_condition_after_an_audited_gm_decision(): void
    {
        [, , $caster, $target, $base] = $this->table([[
            'id' => 'fear', 'name' => 'Fear', 'kind' => 'spell', 'saveAbility' => 'wis', 'saveDc' => 14,
            'saveEffect' => 'none', 'target' => 'single',
            'effect' => ['name' => 'Frightened', 'target' => 'targets', 'trigger' => 'on-failed-save',
                'duration' => ['unit' => 'rounds', 'remaining' => 1], 'modifiers' => [], 'conditions' => ['frightened']],
        ]]);
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'fear', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()])
            ->assertOk()->assertJsonCount(0, 'message.rolls')->json('message');
        $path = $base.'/damage/'.$message['id'];
        $this->getJson($path.'?actorId='.$target->id)->assertOk()->assertJsonPath('preview.pendingSave', true);
        $this->postJson($path, ['actorId' => $target->id])->assertUnprocessable();
        $this->postJson($path.'/save', ['actorId' => $target->id, 'decision' => 'failure', 'reason' => 'Decisão registrada pelo mestre'])->assertOk()->assertJsonPath('save.success', false);
        $this->postJson($path, ['actorId' => $target->id])->assertOk();
        $this->assertDatabaseHas('active_effects', ['actor_id' => $target->id, 'name' => 'Frightened']);
    }
}
