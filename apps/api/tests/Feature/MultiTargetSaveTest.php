<?php

namespace Tests\Feature;

use App\Game\Dice\DiceRoller;
use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiTargetSaveTest extends TestCase
{
    use RefreshDatabase;

    private function encounter(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Saving throws', 'ruleset' => '5e-2024'])->assertCreated()->json();
        $system = ActorStateFactory::character();
        $system['actions'] = [[
            'id' => 'flame', 'name' => 'Explosão', 'kind' => 'spell', 'target' => 'multiple', 'maxTargets' => 3,
            'damageFormula' => '2d6', 'damageType' => 'fire', 'saveAbility' => 'dex', 'saveDc' => 15, 'saveEffect' => 'half',
        ]];
        $caster = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $gm->id,
            'name' => 'Caster', 'type' => 'character', 'system' => $system]);
        $pcSystem = ActorStateFactory::character();
        $pcSystem['hp'] = ['value' => 20, 'max' => 20, 'temp' => 2];
        $pc = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'name' => 'PC', 'type' => 'character', 'system' => $pcSystem]);
        $npcSystem = ActorStateFactory::monster();
        $npcSystem['hp'] = ['value' => 20, 'max' => 20, 'temp' => 0];
        $npcSystem['saves']['dex'] = ['bonus' => 10];
        $npcSystem['damageTraits'] = ['resist' => ['fire']];
        $npc = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => null,
            'name' => 'Private NPC', 'type' => 'monster', 'system' => $npcSystem]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['tokens'] = [
            ['id' => 'caster', 'name' => 'Caster', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $caster->id, 'ownerUserId' => $gm->id],
            ['id' => 'pc', 'name' => 'PC', 'x' => 70, 'y' => 0, 'size' => 1, 'actorId' => $pc->id, 'ownerUserId' => $player->id],
            ['id' => 'npc', 'name' => 'Private NPC', 'x' => 140, 'y' => 0, 'size' => 1, 'actorId' => $npc->id, 'ownerUserId' => null],
        ];
        $scene->update(['state' => $state]);
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => min(10, $max)));
        $base = '/api/scenes/'.$scene->id;
        $message = $this->postJson($base.'/actions', [
            'actorId' => $caster->id, 'actionId' => 'flame', 'targetTokenIds' => ['pc', 'npc'], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('message.save.dc', 15)->assertJsonPath('message.save.ability', 'dex')->json('message');
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();

        return [$gm, $player, $scene, $caster, $pc, $npc, $base, $message['id']];
    }

    public function test_pc_rolls_own_save_gm_rolls_npc_in_batch_and_batch_confirmation_is_atomic_and_idempotent(): void
    {
        [$gm, $player, $scene, $caster, $pc, $npc, $base, $messageId] = $this->encounter();
        $batch = $base.'/actions/'.$messageId.'/save-batch';
        $apply = $base.'/actions/'.$messageId.'/resolve-batch';
        $this->getJson($batch)->assertForbidden();
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [['actorId' => $npc->id]]])->assertForbidden();
        $this->postJson($apply, ['requestId' => (string) Str::uuid(), 'actorIds' => [$pc->id, $npc->id]])->assertForbidden();
        $this->postJson($base.'/damage/'.$messageId.'/save', ['actorId' => $npc->id])->assertForbidden();
        $this->postJson($base.'/damage/'.$messageId.'/save', ['actorId' => $pc->id])->assertOk()
            ->assertJsonPath('save.success', false)->assertJsonPath('save.roll.total', 10);
        $this->assertSame(20, $pc->fresh()->system['hp']['value']);
        Sanctum::actingAs($gm);
        $initial = $this->getJson($batch)->assertOk()->assertJsonCount(2, 'rows')->json();
        $this->assertSame('dex', $initial['save']['ability']);
        $this->assertSame(15, $initial['save']['dc']);
        $this->assertSame(12, $initial['rows'][0]['preview']['damage']);
        $this->assertTrue($initial['rows'][1]['preview']['pendingSave']);
        $request = ['requestId' => (string) Str::uuid(), 'targets' => [['actorId' => $npc->id, 'mode' => 'normal']]];
        $this->postJson($batch, $request)->assertOk()->assertJsonPath('replayed', false);
        $this->postJson($batch, $request)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($batch, [...$request, 'targets' => [['actorId' => $pc->id]]])->assertStatus(409);
        $summary = $this->getJson($batch)->assertOk()->json('rows');
        $this->assertTrue($summary[1]['save']['success']);
        $this->assertSame(20, $summary[1]['save']['roll']['total']);
        $this->assertSame(3, $summary[1]['preview']['damage']); // half, then fire resistance
        $this->assertSame(12, $summary[0]['preview']['damage']);
        $this->assertDatabaseCount('roll_records', 3); // one action damage, one PC save, one NPC save
        $this->assertDatabaseCount('damage_applications', 0);
        $applyData = ['requestId' => (string) Str::uuid(), 'actorIds' => [$pc->id, $npc->id]];
        $this->postJson($apply, $applyData)->assertOk()->assertJsonPath('replayed', false);
        $this->assertSame(10, $pc->fresh()->system['hp']['value']);
        $this->assertSame(0, $pc->fresh()->system['hp']['temp']);
        $this->assertSame(17, $npc->fresh()->system['hp']['value']);
        $this->assertDatabaseCount('damage_applications', 2);
        $this->postJson($apply, $applyData)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($apply, [...$applyData, 'actorIds' => [$pc->id]])->assertStatus(409);
        $this->assertDatabaseCount('damage_applications', 2);
        $this->assertDatabaseCount('roll_records', 3);
        $this->assertDatabaseCount('action_batch_operations', 2);
        $persisted = $this->getJson($batch)->assertOk()->json('rows');
        $this->getJson($batch)->assertOk()
            ->assertJsonCount(2, 'operations')->assertJsonPath('operations.0.kind', 'save')
            ->assertJsonPath('operations.1.kind', 'apply')->assertJsonPath('operations.1.userId', $gm->id);
        $this->assertSame(10, $persisted[0]['application']['resolution']['userId'] === $gm->id ? $pc->fresh()->system['hp']['value'] : -1);
        $this->assertSame(3, $persisted[1]['application']['resolution']['damage']);
        $this->assertSame($player->id, $persisted[0]['save']['userId']);
        $this->assertSame($gm->id, $persisted[1]['save']['userId']);
        $this->assertSame($player->name, $persisted[0]['save']['userName']);
        $this->assertSame($gm->name, $persisted[1]['save']['userName']);
        $this->assertSame(2, DB::table('damage_applications')->where('scene_id', $scene->id)->count());
        $scene->refresh();
        $state = $scene->state;
        $state['chat'] = [];
        $scene->update(['state' => $state]);
        $this->getJson($batch)->assertOk()->assertJsonCount(2, 'rows')->assertJsonCount(2, 'operations');
        $this->getJson($base.'/actions')->assertOk()->assertJsonPath('messages.0.id', $messageId);
        $this->postJson($base.'/damage/'.$messageId, ['actorId' => $pc->id, 'undo' => true])->assertOk();
        $this->postJson($base.'/damage/'.$messageId, ['actorId' => $npc->id, 'undo' => true])->assertOk();
        $this->postJson($base.'/actions/'.$messageId.'/undo', ['actorId' => $caster->id])->assertOk();
        $this->getJson($batch)->assertOk()->assertJsonPath('actionUndone', true)->assertJsonCount(2, 'operations');
        $this->postJson($apply, ['requestId' => (string) Str::uuid(), 'actorIds' => [$pc->id, $npc->id]])->assertStatus(409);
    }

    public function test_manual_review_preserves_original_roll_and_history_and_incomplete_batch_rolls_back_all(): void
    {
        [$gm, , $scene, , $pc, $npc, $base, $messageId] = $this->encounter();
        Sanctum::actingAs($gm);
        $batch = $base.'/actions/'.$messageId.'/save-batch';
        $apply = $base.'/actions/'.$messageId.'/resolve-batch';
        $originalHp = [$pc->fresh()->system['hp']['value'], $npc->fresh()->system['hp']['value']];
        $this->postJson($base.'/damage/'.$messageId.'/save', ['actorId' => $pc->id])->assertOk()
            ->assertJsonPath('save.success', false);
        $this->postJson($apply, ['requestId' => (string) Str::uuid(), 'actorIds' => [$npc->id, $pc->id]])->assertUnprocessable();
        $this->assertDatabaseCount('action_batch_operations', 0);
        $this->assertDatabaseCount('damage_applications', 0);
        $this->assertSame($originalHp, [$pc->fresh()->system['hp']['value'], $npc->fresh()->system['hp']['value']]);
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $pc->id, 'decision' => 'success', 'reason' => 'Decisão aprovada'],
            ['actorId' => $npc->id, 'decision' => 'failure', 'reason' => 'Resistência lendária não usada'],
        ]])->assertOk();
        $rows = $this->getJson($batch)->assertOk()->json('rows');
        $this->assertTrue($rows[0]['save']['success']);
        $this->assertFalse($rows[0]['save']['history'][0]['success']);
        $this->assertFalse($rows[1]['save']['success']);
        $this->assertSame(6, $rows[0]['preview']['damage']);
        $this->assertSame(6, $rows[1]['preview']['damage']);
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $npc->id, 'decision' => 'success', 'reason' => 'Mestre revisou a decisão'],
        ]])->assertOk();
        $after = $this->getJson($batch)->assertOk()->json('rows');
        $this->assertTrue($after[1]['save']['success']);
        $this->assertFalse($after[1]['save']['history'][0]['success']);
        $this->assertSame('Resistência lendária não usada', $after[1]['save']['history'][0]['reason']);
        $this->postJson($apply, ['requestId' => (string) Str::uuid(), 'actorIds' => [$pc->id, $npc->id]])->assertOk();
        $this->assertDatabaseCount('damage_applications', 2);
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $npc->id, 'decision' => 'failure', 'reason' => 'Já aplicado'],
        ]])->assertStatus(409);
    }

    public function test_forged_targets_and_failed_later_step_rollback_prior_rolls_and_writes(): void
    {
        [$gm, , $scene, , $pc, $npc, $base, $messageId] = $this->encounter();
        Sanctum::actingAs($gm);
        $batch = $base.'/actions/'.$messageId.'/save-batch';
        $apply = $base.'/actions/'.$messageId.'/resolve-batch';
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $pc->id], ['actorId' => $pc->id],
        ]])->assertUnprocessable();
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [['actorId' => 99999]]])->assertUnprocessable();
        $this->postJson($apply, ['requestId' => (string) Str::uuid(), 'actorIds' => [$pc->id, 99999]])->assertUnprocessable();
        $this->assertDatabaseCount('action_batch_operations', 0);
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $npc->id, 'mode' => 'advantage'],
            ['actorId' => $pc->id, 'mode' => 'disadvantage'],
        ]])->assertOk();
        $rows = $this->getJson($batch)->assertOk()->json('rows');
        $this->assertSame('advantage', $rows[1]['save']['mode']);
        $this->assertSame('disadvantage', $rows[0]['save']['mode']);
        $this->assertDatabaseCount('roll_records', 3);
        $this->assertSame($messageId, DB::table('action_records')->where('scene_id', $scene->id)->value('message_id'));
    }

    public function test_two_private_npcs_are_rolled_in_one_request_without_rolling_a_player_sheet(): void
    {
        [$gm, , $scene, $caster, $pc, $npc, $base] = $this->encounter();
        Sanctum::actingAs($gm);
        $second = Actor::create(['campaign_id' => $scene->campaign_id, 'owner_user_id' => null,
            'name' => 'Second private NPC', 'type' => 'monster', 'system' => ActorStateFactory::monster()]);
        $state = $scene->fresh()->state;
        $state['tokens'][] = ['id' => 'second-npc', 'name' => 'Second NPC', 'x' => 210, 'y' => 0, 'size' => 1,
            'actorId' => $second->id, 'ownerUserId' => null];
        $scene->update(['state' => $state]);
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'flame',
            'targetTokenIds' => ['pc', 'npc', 'second-npc'], 'requestId' => (string) Str::uuid()])->assertOk()->json('message.id');
        $batch = $base.'/actions/'.$message.'/save-batch';
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $npc->id, 'mode' => 'advantage', 'bonus' => 2],
            ['actorId' => $second->id, 'mode' => 'disadvantage', 'bonus' => -2],
        ]])->assertOk()->assertJsonPath('actorIds', [$npc->id, $second->id]);
        $summary = $this->getJson($batch)->assertOk()->assertJsonCount(3, 'rows')->json('rows');
        $this->assertNull($summary[0]['save']);
        $this->assertSame('advantage', $summary[1]['save']['mode']);
        $this->assertSame('2d20kh1+12', $summary[1]['save']['roll']['formula']);
        $this->assertSame('disadvantage', $summary[2]['save']['mode']);
        $this->assertSame('2d20kl1-2', $summary[2]['save']['roll']['formula']);
        $this->assertDatabaseHas('roll_records', ['step' => 'save:'.$second->id, 'context' => 'save', 'visibility' => 'gm']);
    }

    public function test_batch_resolution_applies_failed_save_effect_only_to_failed_target(): void
    {
        [$gm, , $scene, $caster, $pc, $npc, $base] = $this->encounter();
        Sanctum::actingAs($gm);
        $system = $caster->fresh()->system;
        $system['actions'][0]['saveEffect'] = 'none';
        $system['actions'][0]['effect'] = ['name' => 'Burning mark', 'target' => 'targets', 'trigger' => 'on-failed-save',
            'duration' => ['unit' => 'rounds', 'remaining' => 2], 'modifiers' => [], 'conditions' => ['burning']];
        $caster->update(['system' => $system]);
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'flame',
            'targetActorIds' => [$pc->id, $npc->id], 'requestId' => (string) Str::uuid()])->assertOk()->json('message.id');
        $this->assertDatabaseCount('active_effects', 0);
        $batch = $base.'/actions/'.$message.'/save-batch';
        $this->postJson($batch, ['requestId' => (string) Str::uuid(), 'targets' => [
            ['actorId' => $pc->id, 'decision' => 'success', 'reason' => 'Salvou'],
            ['actorId' => $npc->id, 'decision' => 'failure', 'reason' => 'Falhou'],
        ]])->assertOk();
        $rows = $this->getJson($batch)->assertOk()->json('rows');
        $this->assertSame(0, $rows[0]['preview']['damage']);
        $this->assertSame(6, $rows[1]['preview']['damage']);
        $this->postJson($base.'/actions/'.$message.'/resolve-batch', [
            'requestId' => (string) Str::uuid(), 'actorIds' => [$pc->id, $npc->id],
        ])->assertOk();
        $this->assertSame(20, $pc->fresh()->system['hp']['value']);
        $this->assertSame(14, $npc->fresh()->system['hp']['value']);
        $this->assertSame(1, ActiveEffect::where('name', 'Burning mark')->count());
        $this->assertDatabaseHas('active_effects', ['actor_id' => $npc->id, 'name' => 'Burning mark']);
        $this->assertDatabaseMissing('active_effects', ['actor_id' => $pc->id, 'name' => 'Burning mark']);
    }
}
