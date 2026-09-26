<?php

namespace Tests\Feature;

use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\User;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EffectLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function table(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Condition lifecycle'])->assertCreated()->json();
        $caster = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $gm->id,
            'name' => 'Caster', 'type' => 'character', 'system' => ActorStateFactory::character()]);
        $victim = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'name' => 'Victim', 'type' => 'character', 'system' => ActorStateFactory::character()]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        Sanctum::actingAs($gm);

        return [$gm, $player, $room, $caster, $victim, '/api/scenes/'.$room['scene']['id']];
    }

    private function effect(int $actorId, array $overrides = []): array
    {
        return $this->postJson('/api/actors/'.$actorId.'/effects', [
            'name' => 'Venom', 'source_label' => 'Venomous sting',
            'icon_url' => 'https://example.com/poison.png', 'visibility' => 'public',
            'duration' => ['unit' => 'rounds', 'remaining' => 2],
            'modifiers' => [['path' => 'ac', 'mode' => 'add', 'value' => -2]],
            'conditions' => ['poisoned'],
            ...$overrides,
        ])->assertCreated()->json('effect');
    }

    public function test_private_effects_hide_from_owned_sheet_and_history_but_gm_can_audit_edits_and_removal(): void
    {
        [$gm, $player, , , $victim] = $this->table();
        $private = $this->effect($victim->id, ['name' => 'GM secret', 'visibility' => 'gm',
            'metadata' => ['sourceActorId' => 123456], 'conditions' => ['restrained']]);
        $public = $this->effect($victim->id);
        $this->assertSame('sheet', ActiveEffect::findOrFail($private['id'])->metadata['source']);
        $this->getJson('/api/actors/'.$victim->id.'/effect-history')->assertOk()->assertJsonCount(2, 'events');
        Sanctum::actingAs($player);
        $this->getJson('/api/actors/'.$victim->id)->assertOk()->assertJsonCount(1, 'actor.activeEffects')
            ->assertJsonPath('actor.activeEffects.0.name', 'Venom');
        $this->getJson('/api/actors/'.$victim->id.'/effects')->assertOk()->assertJsonCount(1, 'effects')
            ->assertJsonMissingPath('effects.0.metadata');
        $this->getJson('/api/actors/'.$victim->id.'/effect-history')->assertForbidden();
        $this->patchJson('/api/active-effects/'.$private['id'], ['name' => 'Leaked'])->assertForbidden();
        $this->deleteJson('/api/active-effects/'.$private['id'])->assertForbidden();
        $this->postJson('/api/actors/'.$victim->id.'/effects', ['name' => 'Hidden', 'visibility' => 'gm',
            'duration' => ['unit' => 'rounds', 'remaining' => 1], 'modifiers' => []])->assertForbidden();
        Sanctum::actingAs($gm);
        $this->patchJson('/api/active-effects/'.$private['id'], ['name' => 'Revised secret',
            'duration' => ['unit' => 'rounds', 'phase' => 'end', 'remaining' => 3],
            'reason' => 'Review after adjudication'])->assertOk()->assertJsonPath('effect.name', 'Revised secret')
            ->assertJsonPath('effect.duration.phase', 'end');
        $this->deleteJson('/api/active-effects/'.$public['id'])->assertOk();
        $this->getJson('/api/actors/'.$victim->id.'/effect-history')->assertOk()->assertJsonCount(4, 'events')
            ->assertJsonPath('events.0.event', 'removed')->assertJsonPath('events.1.event', 'updated')
            ->assertJsonPath('events.1.reason', 'Review after adjudication')
            ->assertJsonPath('events.1.user_name', $gm->name);
        $this->assertDatabaseMissing('active_effects', ['id' => $public['id']]);
        $this->postJson('/api/actors/'.$victim->id.'/effects', [
            'name' => 'Bad icon', 'icon_url' => 'http://example.com/icon.png',
            'duration' => ['unit' => 'rounds', 'remaining' => 1], 'modifiers' => [],
        ])->assertUnprocessable();
    }

    public function test_turn_start_end_round_and_rest_expire_once_without_reversing_on_previous_turn(): void
    {
        [, , , $first, $second, $base] = $this->table();
        $end = $this->effect($first->id, ['name' => 'End of my turn', 'duration' => ['unit' => 'rounds', 'remaining' => 1, 'phase' => 'end']]);
        $start = $this->effect($second->id, ['name' => 'Start of my turn', 'duration' => ['unit' => 'rounds', 'remaining' => 1, 'phase' => 'start']]);
        $round = $this->effect($first->id, ['name' => 'Two rounds', 'duration' => ['unit' => 'rounds', 'remaining' => 2]]);
        $repeat = $this->effect($first->id, ['name' => 'Two turns', 'duration' => ['unit' => 'rounds', 'remaining' => 2, 'phase' => 'end']]);
        $rest = $this->effect($first->id, ['name' => 'Short rest', 'duration' => ['unit' => 'until-short-rest']]);
        $this->postJson($base.'/combat/start')->assertOk();
        $this->postJson($base.'/combat/combatants', ['actorId' => $first->id])->assertOk();
        $this->postJson($base.'/combat/combatants', ['actorId' => $second->id])->assertOk();
        $this->postJson($base.'/combat/next')->assertOk()->assertJsonPath('combat.turn', 1);
        $this->assertSame(1, ActiveEffect::findOrFail($repeat['id'])->duration['remaining']);
        $this->assertFalse(ActiveEffect::findOrFail($end['id'])->active);
        $this->assertFalse(ActiveEffect::findOrFail($start['id'])->active);
        $this->assertSame(2, ActiveEffect::findOrFail($round['id'])->duration['remaining']);
        $this->postJson($base.'/combat/prev')->assertOk()->assertJsonPath('combat.turn', 0);
        $this->postJson($base.'/combat/next')->assertOk()->assertJsonPath('combat.turn', 1);
        $this->assertSame(1, ActiveEffect::findOrFail($repeat['id'])->duration['remaining']);
        $this->postJson($base.'/combat/next')->assertOk()->assertJsonPath('combat.round', 2);
        $this->assertSame(1, ActiveEffect::findOrFail($round['id'])->duration['remaining']);
        $this->postJson($base.'/combat/prev')->assertOk();
        $this->assertSame(1, ActiveEffect::findOrFail($round['id'])->duration['remaining']);
        $this->postJson($base.'/combat/next')->assertOk();
        $this->postJson($base.'/combat/next')->assertOk();
        $this->postJson($base.'/combat/next')->assertOk();
        $this->assertFalse(ActiveEffect::findOrFail($round['id'])->active);
        $this->assertFalse(ActiveEffect::findOrFail($repeat['id'])->active);
        $this->postJson('/api/actors/'.$first->id.'/rest', ['rest' => 'short'])->assertOk();
        $this->assertFalse(ActiveEffect::findOrFail($rest['id'])->active);
        $this->assertDatabaseHas('active_effect_events', ['effect_id' => $end['id'], 'event' => 'expired', 'reason' => 'turn-end']);
        $this->assertDatabaseHas('active_effect_events', ['effect_id' => $start['id'], 'event' => 'expired', 'reason' => 'turn-start']);
        $this->assertDatabaseHas('active_effect_events', ['effect_id' => $round['id'], 'event' => 'expired', 'reason' => 'round-end']);
        $this->assertDatabaseHas('active_effect_events', ['effect_id' => $rest['id'], 'event' => 'expired', 'reason' => 'short-rest']);
    }

    public function test_concentration_replaces_linked_effects_and_unconditional_conditions_change_roll_modes(): void
    {
        [$gm, $player, $room, $caster, $victim, $base] = $this->table();
        $system = $caster->system;
        $system['actions'] = [
            ['id' => 'hold', 'name' => 'Hold', 'kind' => 'spell', 'target' => 'single', 'concentration' => true,
                'effect' => ['name' => 'Bound', 'target' => 'targets', 'trigger' => 'on-use',
                    'duration' => ['unit' => 'rounds', 'remaining' => 3], 'modifiers' => [], 'conditions' => ['restrained']]],
            ['id' => 'fog', 'name' => 'Fog', 'kind' => 'spell', 'target' => 'single', 'concentration' => true,
                'effect' => ['name' => 'Blinded', 'target' => 'targets', 'trigger' => 'on-use',
                    'duration' => ['unit' => 'rounds', 'remaining' => 3], 'modifiers' => [], 'conditions' => ['blinded']]],
            ['id' => 'jab', 'name' => 'Jab', 'kind' => 'attack', 'attackFormula' => '1d20+3', 'damageFormula' => '1d4', 'damageType' => 'bludgeoning'],
        ];
        $caster->update(['system' => $system]);
        $hold = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'hold',
            'targetActorIds' => [$victim->id], 'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $first = ActiveEffect::where('actor_id', $victim->id)->firstOrFail();
        $this->assertSame($caster->id, $first->concentration_actor_id);
        $this->assertSame($hold['concentrationId'], $first->concentration_id);
        $this->assertContains('restrained', app(ActiveEffectEngine::class)->effectiveSystem($victim)['conditions']);
        $fog = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'fog',
            'targetActorIds' => [$victim->id], 'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $this->assertFalse($first->fresh()->active);
        $this->assertSame($fog['concentrationId'], $caster->fresh()->system['concentration']['id']);
        $this->assertNotContains('restrained', app(ActiveEffectEngine::class)->effectiveSystem($victim)['conditions']);
        $this->assertContains('blinded', app(ActiveEffectEngine::class)->effectiveSystem($victim)['conditions']);
        $this->assertDatabaseHas('active_effect_events', ['effect_id' => $first->id, 'reason' => 'concentration-ended']);
        $system = $caster->fresh()->system;
        $system['concentration'] = null;
        $caster->update(['system' => $system]);
        $this->assertFalse(ActiveEffect::where('actor_id', $victim->id)->latest('id')->firstOrFail()->active);
        $this->effect($caster->id, ['name' => 'Poisoned', 'conditions' => ['poisoned'], 'modifiers' => []]);
        Sanctum::actingAs($player);
        $this->postJson($base.'/rolls', ['actorId' => $caster->id, 'context' => 'attack', 'formula' => 'd20+3',
            'requestId' => (string) Str::uuid()])->assertForbidden();
        Sanctum::actingAs($gm);
        $this->postJson($base.'/rolls', ['actorId' => $caster->id, 'context' => 'attack', 'formula' => 'd20+3',
            'requestId' => (string) Str::uuid()])->assertOk()->assertJsonPath('roll.mode', 'disadvantage');
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'jab',
            'mode' => 'advantage', 'requestId' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('message.rolls.0.mode', 'normal');
        $this->effect($caster->id, ['name' => 'Restrained', 'conditions' => ['restrained'], 'modifiers' => []]);
        $this->postJson($base.'/rolls', ['actorId' => $caster->id, 'context' => 'save', 'ability' => 'dex', 'formula' => 'd20+2',
            'requestId' => (string) Str::uuid()])->assertOk()->assertJsonPath('roll.mode', 'disadvantage');
        $this->postJson($base.'/rolls', ['actorId' => $caster->id, 'context' => 'save', 'ability' => 'con', 'formula' => 'd20+2',
            'requestId' => (string) Str::uuid()])->assertOk()->assertJsonPath('roll.mode', 'normal');
    }

    public function test_incapacitating_effect_immediately_breaks_concentration_and_blocks_actions_without_leaking_hidden_details(): void
    {
        [$gm, $player, , $caster, $victim, $base] = $this->table();
        $system = $caster->system;
        $system['actions'] = [
            ['id' => 'private-ward', 'name' => 'Secret ward', 'kind' => 'spell', 'target' => 'single',
                'visibility' => 'gm', 'concentration' => true,
                'effect' => ['name' => 'Hidden shield', 'target' => 'targets', 'trigger' => 'on-use',
                    'duration' => ['unit' => 'rounds', 'remaining' => 3], 'modifiers' => [['path' => 'ac', 'mode' => 'add', 'value' => 2]], 'conditions' => []]],
            ['id' => 'jab', 'name' => 'Jab', 'kind' => 'attack', 'attackFormula' => 'd20+3', 'damageFormula' => 'd4'],
        ];
        $caster->update(['system' => $system]);
        $victim->update(['shared' => true]);
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'private-ward',
            'targetActorIds' => [$victim->id], 'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $effect = ActiveEffect::where('actor_id', $victim->id)->firstOrFail();
        Sanctum::actingAs($player);
        $this->getJson('/api/actors/'.$victim->id)->assertOk()->assertJsonCount(0, 'actor.activeEffects');
        $this->getJson($base)->assertOk()->assertJsonMissing(['concentrationId' => $message['concentrationId']]);
        Sanctum::actingAs($gm);
        $this->effect($caster->id, ['name' => 'Stun', 'conditions' => ['stunned'], 'modifiers' => []]);
        $stun = ActiveEffect::where('actor_id', $caster->id)->latest('id')->firstOrFail();
        $this->assertSame(['stunned'], $stun->conditions);
        $this->assertTrue($stun->active);
        $this->assertNull($caster->fresh()->system['concentration']);
        $this->assertFalse($effect->fresh()->active);
        $this->assertDatabaseHas('active_effect_events', ['effect_id' => $effect->id, 'reason' => 'concentration-ended']);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'jab',
            'requestId' => (string) Str::uuid()])->assertUnprocessable();
    }
}
