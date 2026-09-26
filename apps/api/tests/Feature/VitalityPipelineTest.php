<?php

namespace Tests\Feature;

use App\Game\Dice\DiceRoller;
use App\Models\Actor;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VitalityPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function party(array $actions): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Vitality rules'])->assertCreated()->json();
        $source = ActorStateFactory::character();
        $source['actions'] = $actions;
        $caster = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $gm->id,
            'type' => 'character', 'name' => 'Caster', 'system' => $source]);
        $target = ActorStateFactory::character();
        $target['hp'] = ['value' => 10, 'max' => 10, 'temp' => 2];
        $target['damageTraits'] = ['resist' => ['fire'], 'immune' => ['poison'], 'vulnerable' => ['slashing']];
        $target['damageReduction'] = 3;
        $victim = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'type' => 'character', 'name' => 'Target', 'system' => $target]);
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => min(4, $max)));

        return [$gm, $player, $room, $caster, $victim, '/api/scenes/'.$room['scene']['id']];
    }

    public function test_mixed_damage_is_rolled_once_per_part_and_confirms_with_one_canonical_hp_transition(): void
    {
        [$gm, , , $caster, $target, $base] = $this->party([[
            'id' => 'elemental', 'name' => 'Elemental slash', 'kind' => 'attack', 'target' => 'single',
            'damageParts' => [
                ['formula' => '2d6', 'damageType' => 'fire'],
                ['formula' => '1d8', 'damageType' => 'slashing'],
                ['formula' => '1d4', 'damageType' => 'poison'],
            ],
        ]]);
        $request = ['actorId' => $caster->id, 'actionId' => 'elemental',
            'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()
            ->assertJsonCount(3, 'message.rolls')->assertJsonPath('message.rolls.0.damageType', 'fire')
            ->assertJsonPath('message.rolls.1.damageType', 'slashing')
            ->assertJsonPath('message.rolls.2.damageType', 'poison')->json('message');
        $this->assertSame([8, 4, 4], array_column($message['rolls'], 'total'));
        $this->assertDatabaseCount('roll_records', 3);
        $this->assertDatabaseHas('roll_records', ['id' => $message['rolls'][1]['id'], 'step' => 'damage:1']);
        $this->assertSame(10, $target->fresh()->system['hp']['value']);
        $path = $base.'/damage/'.$message['id'];
        $this->getJson($path.'?actorId='.$target->id)->assertOk()
            ->assertJsonPath('preview.rolledDamage', 16)
            ->assertJsonPath('preview.components.0.calculated', 4)
            ->assertJsonPath('preview.components.1.calculated', 8)
            ->assertJsonPath('preview.components.2.calculated', 0)
            ->assertJsonPath('preview.automaticDamage', 9)
            ->assertJsonPath('preview.damage', 9);
        $this->postJson($path, ['actorId' => $target->id])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 3)->assertJsonPath('actor.system.hp.temp', 0);
        $application = $this->getJson($path.'?actorId='.$target->id)->assertOk()->json('application');
        $this->assertGreaterThan(0, $application['operationId']);
        $this->assertSame(2, $application['resolution']['hpEffect']['absorbedTemp']);
        $this->assertSame(7, $application['resolution']['hpEffect']['lostHp']);
        $this->assertContains('Ficha do alvo: imunidade a poison', $application['resolution']['sources']);
        $this->assertContains('Ficha: PV temporários absorvem antes dos PV', $application['resolution']['sources']);
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->postJson($path, ['actorId' => $target->id])->assertOk()->assertJsonPath('actor.system.hp.value', 3);
        $this->assertDatabaseCount('roll_records', 3);
        $this->assertDatabaseCount('damage_applications', 1);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 10)->assertJsonPath('actor.system.hp.temp', 2);
        $this->assertDatabaseCount('damage_applications', 1);
    }

    public function test_healing_preview_excess_gm_adjustment_and_owner_permissions_are_audited_once(): void
    {
        [$gm, $player, $room, $caster, $target, $base] = $this->party([[
            'id' => 'heal', 'name' => 'Restoration', 'kind' => 'spell', 'target' => 'single', 'healingFormula' => '2d8+2',
        ]]);
        $system = $target->system;
        $system['hp']['value'] = 9;
        $target->update(['system' => $system]);
        $message = $this->postJson($base.'/actions', [
            'actorId' => $caster->id, 'actionId' => 'heal', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('message.rolls.0.total', 10)->json('message');
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $path = $base.'/actions/'.$message['id'].'/heal';
        $this->getJson($path.'?actorId='.$target->id)->assertOk()
            ->assertJsonPath('preview.restored', 1)->assertJsonPath('preview.excess', 9);
        $this->postJson($path, ['actorId' => $target->id, 'confirmed' => true,
            'amountOverride' => 4, 'reason' => 'Tentativa indevida'])->assertForbidden();
        Sanctum::actingAs($gm);
        $this->postJson($path, ['actorId' => $target->id, 'confirmed' => true, 'amountOverride' => 4])->assertUnprocessable();
        $payload = ['actorId' => $target->id, 'confirmed' => true, 'amountOverride' => 4, 'reason' => 'Redução da cura por decisão da mesa'];
        $result = $this->postJson($path, $payload)->assertOk()->assertJsonPath('actor.system.hp.value', 10)
            ->assertJsonPath('actor.system.hp.temp', 2)->json();
        $this->assertGreaterThan(0, $result['application']['operationId']);
        $this->assertSame(1, $result['application']['amount']);
        $this->assertSame(3, $result['application']['resolution']['excess']);
        $this->assertSame(4, $result['application']['resolution']['requested']);
        $this->assertSame($gm->id, $result['application']['resolution']['userId']);
        $this->assertDatabaseCount('action_healing_applications', 1);
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('actor.system.hp.value', 10);
        $this->postJson($path, [...$payload, 'amountOverride' => 6])->assertStatus(409);
        $this->assertDatabaseCount('roll_records', 1);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk()->assertJsonPath('actor.system.hp.value', 9);
        $this->postJson($path, $payload)->assertStatus(409);
    }

    public function test_healing_at_zero_resets_death_saves_and_undo_restores_original_state_safely(): void
    {
        [$gm, , , $caster, $target, $base] = $this->party([[
            'id' => 'heal', 'name' => 'Healing word', 'kind' => 'spell', 'target' => 'single', 'healingFormula' => '1d4',
        ]]);
        $system = $target->system;
        $system['hp']['value'] = 0;
        $system['deathSaves'] = ['success' => 2, 'failure' => 1];
        $system['conditions'] = ['estabilizado'];
        $target->update(['system' => $system]);
        $message = $this->postJson($base.'/actions', [
            'actorId' => $caster->id, 'actionId' => 'heal', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->json('message');
        $path = $base.'/actions/'.$message['id'].'/heal';
        $this->postJson($path, ['actorId' => $target->id, 'confirmed' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 4)
            ->assertJsonPath('actor.system.deathSaves.success', 0)
            ->assertJsonPath('actor.system.deathSaves.failure', 0)->assertJsonPath('actor.system.conditions', []);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 0)
            ->assertJsonPath('actor.system.deathSaves.success', 2)
            ->assertJsonPath('actor.system.deathSaves.failure', 1)
            ->assertJsonPath('actor.system.conditions', ['estabilizado']);
    }

    public function test_critical_doubles_every_typed_damage_die_and_preserves_modifiers(): void
    {
        [, , , $caster, $target, $base] = $this->party([[
            'id' => 'crit', 'name' => 'Mixed critical', 'kind' => 'attack', 'target' => 'single',
            'attackFormula' => '1d20+5',
            'damageParts' => [['formula' => '1d6+2', 'damageType' => 'fire'],
                ['formula' => '1d4', 'damageType' => 'slashing']],
        ]]);
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => $max));
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'crit',
            'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('message.rolls.0.critical', true)
            ->assertJsonPath('message.rolls.1.formula', '2d6+2')
            ->assertJsonPath('message.rolls.2.formula', '2d4')->json('message');
        $this->assertSame([25, 14, 8], array_column($message['rolls'], 'total'));
        $this->assertDatabaseCount('roll_records', 3);
        $this->getJson($base.'/damage/'.$message['id'].'?actorId='.$target->id)->assertOk()
            ->assertJsonPath('preview.rolledDamage', 22)
            ->assertJsonPath('preview.damage', 20); // fire 7 + slashing 16 - reduction 3
    }

    public function test_hybrid_action_keeps_attack_then_typed_damage_then_healing_in_one_persisted_pipeline(): void
    {
        [, , , $caster, $target, $base] = $this->party([[
            'id' => 'hybrid', 'name' => 'Mixed restoring hit', 'kind' => 'spell', 'target' => 'single',
            'attackFormula' => '1d20+3',
            'damageParts' => [['formula' => '1d6', 'damageType' => 'fire'], ['formula' => '1d4', 'damageType' => 'force']],
            'healingFormula' => '1d4+2',
        ]]);
        $request = ['actorId' => $caster->id, 'actionId' => 'hybrid', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()->assertJsonCount(4, 'message.rolls')->json('message');
        $this->assertSame(['attack', 'damage', 'damage', 'heal'], array_column($message['pipeline'], 'kind'));
        $this->assertEqualsCanonicalizing(array_column($message['rolls'], 'id'), array_column($message['pipeline'], 'rollId'));
        $this->assertDatabaseCount('roll_records', 4);
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->assertDatabaseCount('roll_records', 4);
    }

    public function test_rejects_conflicting_single_and_typed_formulas_before_creating_any_roll(): void
    {
        [, , , $caster, $target, $base] = $this->party([[
            'id' => 'invalid', 'name' => 'Invalid mixed', 'kind' => 'attack', 'target' => 'single',
            'damageFormula' => '2d6', 'damageParts' => [['formula' => 'd6', 'damageType' => 'fire']],
        ]]);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'invalid',
            'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid()])->assertStatus(422);
        $this->assertDatabaseCount('action_records', 0);
        $this->assertDatabaseCount('roll_records', 0);
    }

    public function test_normal_healing_cannot_resurrect_dead_character_or_change_hp_on_rejection(): void
    {
        [, , , $caster, $target, $base] = $this->party([[
            'id' => 'heal', 'name' => 'Simple healing', 'kind' => 'spell', 'target' => 'single', 'healingFormula' => '1d4',
        ]]);
        $system = $target->system;
        $system['hp']['value'] = 0;
        $system['conditions'] = ['morto'];
        $target->update(['system' => $system]);
        $messageId = $this->postJson($base.'/actions', [
            'actorId' => $caster->id, 'actionId' => 'heal', 'targetActorIds' => [$target->id], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->json('message.id');
        $this->getJson($base.'/actions/'.$messageId.'/heal?actorId='.$target->id)
            ->assertOk()->assertJsonPath('preview.blockedReason', 'Cura comum não ressuscita personagem morto.');
        $this->postJson($base.'/actions/'.$messageId.'/heal', ['actorId' => $target->id, 'confirmed' => true])->assertStatus(422);
        $this->assertSame(0, $target->fresh()->system['hp']['value']);
        $this->assertSame(['morto'], $target->fresh()->system['conditions']);
        $this->assertDatabaseCount('action_healing_applications', 0);
    }
}
