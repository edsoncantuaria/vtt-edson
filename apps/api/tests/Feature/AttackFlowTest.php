<?php

namespace Tests\Feature;

use App\Game\Dice\DiceRoller;
use App\Models\Actor;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttackFlowTest extends TestCase
{
    use RefreshDatabase;

    private function battle(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Ataque completo'])->assertCreated()->json();
        $hero = ActorStateFactory::character();
        $hero['actions'] = [[
            'id' => 'sword', 'name' => 'Espada longa', 'kind' => 'attack', 'target' => 'single',
            'attackFormula' => '1d20+3', 'damageFormula' => '1d8+2d4+2', 'damageType' => 'slashing', 'rangeFeet' => 30,
        ]];
        $source = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'name' => 'Herói', 'type' => 'character', 'system' => $hero]);
        $monster = ActorStateFactory::monster();
        $monster['ac'] = 25;
        $monster['hp'] = ['value' => 20, 'max' => 20, 'temp' => 3];
        $target = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => null,
            'name' => 'Ficha secreta', 'type' => 'monster', 'system' => $monster]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['tokens'] = [
            ['id' => 'hero', 'name' => 'Herói', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $source->id, 'ownerUserId' => $player->id],
            ['id' => 'enemy', 'name' => 'Criatura visível', 'x' => 70, 'y' => 0, 'size' => 1, 'actorId' => $target->id, 'ownerUserId' => null],
        ];
        $scene->update(['state' => $state]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();

        return [$gm, $player, $scene, $source, $target, '/api/scenes/'.$scene->id];
    }

    public function test_private_ac_is_only_in_authorized_preview_and_gm_correction_is_audited_and_idempotent(): void
    {
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => min(10, $max)));
        [$gm, $player, $scene, $source, $target, $base] = $this->battle();
        $request = ['actorId' => $source->id, 'actionId' => 'sword', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $request)->assertOk()
            ->assertJsonPath('message.targetActorIds', [])->json('message');
        $this->assertArrayNotHasKey('ac', $message);
        $this->getJson($base.'/damage/'.$message['id'].'?actorId='.$target->id)->assertForbidden();
        $this->getJson($base)->assertOk()->assertJsonPath('scene.state.tokens.1.actorId', null);
        Sanctum::actingAs($gm);
        $path = $base.'/damage/'.$message['id'];
        $preview = $this->getJson($path.'?actorId='.$target->id)->assertOk()
            ->assertJsonPath('preview.attack.ac', 25)->assertJsonPath('preview.attack.hit', false)
            ->assertJsonPath('preview.damage', 0)->json('preview');
        $this->assertSame(20, $target->fresh()->system['hp']['value']);
        $this->postJson($path, ['actorId' => $target->id, 'hitDecision' => 'hit'])->assertUnprocessable();
        $corrected = ['actorId' => $target->id, 'hitDecision' => 'hit', 'damageOverride' => 7, 'reason' => 'Cobertura removida pela mesa'];
        $this->postJson($path, $corrected)->assertOk()
            ->assertJsonPath('actor.system.hp.value', 16)->assertJsonPath('actor.system.hp.temp', 0);
        $resolved = $this->getJson($path.'?actorId='.$target->id)->assertOk()->json('application');
        $this->assertSame(7, $resolved['resolution']['damage']);
        $this->assertFalse($resolved['resolution']['attack']['automaticHit']);
        $this->assertTrue($resolved['resolution']['attack']['hit']);
        $this->assertSame($preview['attack']['total'], $resolved['resolution']['attack']['total']);
        $this->assertSame('Cobertura removida pela mesa', $resolved['resolution']['reason']);
        $this->assertSame(7, $resolved['resolution']['damageOverride']);
        $this->assertDatabaseCount('roll_records', 2);
        $this->assertDatabaseCount('damage_applications', 1);
        $this->postJson($path, $corrected)->assertOk()->assertJsonPath('actor.system.hp.value', 16);
        $this->assertCount(2, $scene->fresh()->state['chat']);
        Sanctum::actingAs($player);
        $state = $this->getJson($base)->assertOk()->json('scene.state');
        $this->assertStringContainsString('7 PV', $state['chat'][1]['text']);
        $this->assertStringNotContainsString('CA', $state['chat'][1]['text']);
        $this->assertStringNotContainsString('16', $state['chat'][1]['text']);
        $this->assertNull($state['tokens'][1]['actorId']);
        Sanctum::actingAs($gm);
        $correction = [
            'actorId' => $target->id, 'correct' => true, 'requestId' => (string) Str::uuid(),
            'damageOverride' => 9, 'hitDecision' => 'hit', 'reason' => 'Correção da resistência após confirmação',
        ];
        $this->postJson($path, $correction)->assertOk()->assertJsonPath('corrected', true)
            ->assertJsonPath('replayed', false)->assertJsonPath('actor.system.hp.value', 14);
        $this->getJson($path.'?actorId='.$target->id)->assertOk()
            ->assertJsonPath('application.resolution.corrections.0.previousDamage', 7)
            ->assertJsonPath('application.resolution.corrections.0.damage', 9)
            ->assertJsonPath('application.resolution.attack.total', $preview['attack']['total']);
        $this->postJson($path, $correction)->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('actor.system.hp.value', 14);
        $this->postJson($path, [...$correction, 'damageOverride' => 8])->assertStatus(409);
        $this->assertDatabaseCount('damage_applications', 1);
        $this->assertDatabaseCount('roll_records', 2);
        $this->assertCount(3, $scene->fresh()->state['chat']);
        $system = $target->fresh()->system;
        $system['hp']['value'] = 13;
        $target->update(['system' => $system]);
        $this->postJson($path, [...$correction, 'requestId' => (string) Str::uuid()])->assertStatus(409);
        $system['hp']['value'] = 14;
        $target->update(['system' => $system]);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 20)->assertJsonPath('actor.system.hp.temp', 3);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertOk();
        $this->assertCount(4, $scene->fresh()->state['chat']);
    }

    public function test_default_miss_applies_zero_and_only_gm_can_correct_or_ignore_damage(): void
    {
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => min(10, $max)));
        [$gm, $player, , $source, $target, $base] = $this->battle();
        $message = $this->postJson($base.'/actions', [
            'actorId' => $source->id, 'actionId' => 'sword', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->json('message');
        $path = $base.'/damage/'.$message['id'];
        $this->postJson($path, ['actorId' => $target->id, 'damageOverride' => 5, 'reason' => 'Injeção'])->assertForbidden();
        Sanctum::actingAs($gm);
        $this->postJson($path, ['actorId' => $target->id])->assertOk()->assertJsonPath('actor.system.hp.value', 20);
        $resolution = $this->getJson($path.'?actorId='.$target->id)->assertOk()->json('application.resolution');
        $this->assertFalse($resolution['hit']);
        $this->assertSame(0, $resolution['damage']);
        $this->assertDatabaseCount('damage_applications', 1);
        $this->postJson($path, ['actorId' => $target->id, 'hitDecision' => 'hit', 'damageOverride' => 10, 'reason' => 'Change after confirmation'])
            ->assertStatus(409);
        $this->postJson($path, ['actorId' => $target->id, 'correct' => true, 'requestId' => (string) Str::uuid(),
            'hitDecision' => 'hit', 'damageOverride' => 10, 'reason' => 'Mestre decidiu acerto após revisar cobertura'])
            ->assertOk()->assertJsonPath('actor.system.hp.value', 13);
        $target->update(['owner_user_id' => $player->id]);
        Sanctum::actingAs($player);
        $this->postJson($path, ['actorId' => $target->id, 'correct' => true, 'requestId' => (string) Str::uuid(),
            'damageOverride' => 0, 'reason' => 'Tentativa de editar meu próprio dano'])->assertForbidden();
        $this->assertDatabaseCount('damage_applications', 1);
    }

    public function test_natural_twenty_doubles_all_damage_dice_and_gm_can_force_miss_without_leaking_ac(): void
    {
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => $max));
        [$gm, , , $source, $target, $base] = $this->battle();
        $message = $this->postJson($base.'/actions', [
            'actorId' => $source->id, 'actionId' => 'sword', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('message.rolls.0.critical', true)
            ->assertJsonPath('message.rolls.1.formula', '2d8+4d4+2')->json('message');
        Sanctum::actingAs($gm);
        $path = $base.'/damage/'.$message['id'];
        $this->getJson($path.'?actorId='.$target->id)->assertOk()->assertJsonPath('preview.attack.hit', true);
        $this->postJson($path, ['actorId' => $target->id, 'hitDecision' => 'miss', 'factor' => 1,
            'reason' => 'Erro decidido pelo mestre'])->assertUnprocessable();
        $this->postJson($path, ['actorId' => $target->id, 'hitDecision' => 'miss', 'reason' => 'Cobertura total'])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 20);
        $resolved = $this->getJson($path.'?actorId='.$target->id)->assertOk()->json('application.resolution');
        $this->assertTrue($resolved['attack']['automaticHit']);
        $this->assertFalse($resolved['attack']['hit']);
        $this->assertSame(0, $resolved['damage']);
        $this->assertTrue($resolved['attack']['critical']);
        $this->assertDatabaseCount('roll_records', 2);
    }

    public function test_late_correction_cannot_revive_a_character_after_death_saves_have_started(): void
    {
        [$gm, , , $source, $target, $base] = $this->battle();
        $target->update(['type' => 'character']);
        $message = $this->postJson($base.'/actions', [
            'actorId' => $source->id, 'actionId' => 'sword', 'targetTokenIds' => ['enemy'], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->json('message');
        Sanctum::actingAs($gm);
        $path = $base.'/damage/'.$message['id'];
        $this->postJson($path, ['actorId' => $target->id, 'damageOverride' => 100, 'reason' => 'Dano letal confirmado'])
            ->assertOk()->assertJsonPath('actor.system.hp.value', 0);
        $system = $target->fresh()->system;
        $system['deathSaves']['success'] = 1;
        $target->update(['system' => $system]);
        $this->postJson($path, ['actorId' => $target->id, 'correct' => true, 'requestId' => (string) Str::uuid(),
            'damageOverride' => 0, 'reason' => 'Correção tardia após salvaguarda'])->assertStatus(409);
        $this->postJson($path, ['actorId' => $target->id, 'undo' => true])->assertStatus(409);
        $this->assertSame(0, $target->fresh()->system['hp']['value']);
        $this->assertDatabaseCount('damage_applications', 1);
    }
}
