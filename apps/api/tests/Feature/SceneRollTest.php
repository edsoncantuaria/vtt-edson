<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\RollTable;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SceneRollTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $gm = User::factory()->create();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $observer = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Mesa']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Entrada', 'published' => true, 'state' => SceneStateFactory::empty()]);
        foreach ([[$gm, 'gm'], [$owner, 'player'], [$other, 'player'], [$observer, 'observer']] as [$user, $role]) {
            SceneMember::create(['scene_id' => $scene->id, 'user_id' => $user->id, 'role' => $role]);
        }
        $actor = Actor::create([
            'campaign_id' => $campaign->id, 'owner_user_id' => $owner->id,
            'type' => 'character', 'name' => 'Hero', 'system' => ActorStateFactory::character(),
        ]);

        return [$gm, $owner, $other, $observer, $scene, $actor];
    }

    public function test_authoritative_roll_has_a_stable_id_and_retry_cannot_forge_or_reroll_it(): void
    {
        [, $player, , , $scene, $actor] = $this->fixture();
        Sanctum::actingAs($player);
        $url = '/api/scenes/'.$scene->id.'/rolls';
        $data = [
            'requestId' => (string) Str::uuid(), 'actorId' => $actor->id, 'context' => 'skill',
            'formula' => 'd20+2', 'modifier' => 1, 'extraDice' => 'd4', 'mode' => 'advantage',
            'label' => 'Hero · Percepção', 'visibility' => 'public', 'total' => 9999,
        ];
        $first = $this->postJson($url, $data)->assertOk()->assertJsonPath('roll.replayed', false)
            ->assertJsonPath('roll.formula', '2d20kh1+2+1+d4');
        $id = $first->json('roll.id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertNotSame(9999, $first->json('roll.total'));
        $this->assertCount(3, $first->json('roll.rolls'));
        $this->assertSame($id, $first->json('message.id'));
        $this->assertSame($id, $first->json('message.rollId'));
        $this->assertDatabaseCount('roll_records', 1);

        $this->postJson($url, $data)->assertOk()->assertJsonPath('roll.replayed', true)
            ->assertJsonPath('roll.id', $id)->assertJsonPath('roll.total', $first->json('roll.total'));
        $this->assertCount(1, $scene->fresh()->state['chat']);
        $this->assertDatabaseCount('roll_records', 1);
        $this->postJson($url, [...$data, 'formula' => 'd20+3'])->assertStatus(409);

        // Chat may be trimmed or a websocket lost: the immutable history still resolves the same roll.
        $state = $scene->fresh()->state;
        $state['chat'] = [];
        $scene->refresh()->update(['state' => $state]);
        $this->assertSame([], $scene->fresh()->state['chat']);
        $this->getJson($url)->assertOk()->assertJsonPath('rolls.0.id', $id);
        $this->postJson($url, $data)->assertOk()->assertJsonPath('roll.id', $id);
        $this->assertSame([], $scene->fresh()->state['chat']);
    }

    public function test_gm_only_and_private_records_never_enter_public_chat_or_other_players_history(): void
    {
        [$gm, $owner, $other, $observer, $scene] = $this->fixture();
        $url = '/api/scenes/'.$scene->id.'/rolls';
        Sanctum::actingAs($owner);
        $gmRoll = $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => 'd20', 'visibility' => 'gm',
        ])->assertOk()->assertJsonPath('message', null)->json('roll.id');
        $privateRoll = $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => 'd20',
            'visibility' => 'private', 'recipientUserId' => $other->id,
        ])->assertOk()->assertJsonPath('message', null)->json('roll.id');
        $this->assertSame([], $scene->fresh()->state['chat']);

        $own = array_column($this->getJson($url)->assertOk()->json('rolls'), 'id');
        $this->assertContains($gmRoll, $own);
        $this->assertContains($privateRoll, $own);
        Sanctum::actingAs($gm);
        $this->assertContains($gmRoll, array_column($this->getJson($url)->assertOk()->json('rolls'), 'id'));
        $this->assertNotContains($privateRoll, array_column($this->getJson($url)->assertOk()->json('rolls'), 'id'));
        Sanctum::actingAs($other);
        $visible = array_column($this->getJson($url)->assertOk()->json('rolls'), 'id');
        $this->assertNotContains($gmRoll, $visible);
        $this->assertContains($privateRoll, $visible);
        Sanctum::actingAs($observer);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'rolls');
        $this->postJson($url, ['requestId' => (string) Str::uuid(), 'formula' => 'd20'])->assertForbidden();
    }

    public function test_authorization_context_and_formula_validation_reject_illegal_mutations_without_rows(): void
    {
        [$gm, $owner, $other, , $scene, $actor] = $this->fixture();
        $url = '/api/scenes/'.$scene->id.'/rolls';
        Sanctum::actingAs($other);
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'actorId' => $actor->id, 'formula' => 'd20',
        ])->assertForbidden();
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => 'd20', 'visibility' => 'private',
            'recipientUserId' => User::factory()->create()->id,
        ])->assertUnprocessable();
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => '2d20kh3',
        ])->assertUnprocessable();
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => 'd8', 'mode' => 'advantage',
        ])->assertUnprocessable();
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => 'd20', 'extraDice' => '100d1000',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('roll_records', 0);
        Sanctum::actingAs($gm);
        $foreign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Outra']);
        $foreignActor = Actor::create(['campaign_id' => $foreign->id, 'name' => 'Invasor', 'type' => 'monster', 'system' => ActorStateFactory::monster()]);
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'actorId' => $foreignActor->id, 'formula' => 'd20',
        ])->assertUnprocessable();
        Sanctum::actingAs($owner);
        $this->postJson($url, [
            'requestId' => (string) Str::uuid(), 'formula' => 'd20',
            'visibility' => 'public', 'recipientUserId' => $other->id,
        ])->assertUnprocessable();
        $this->assertSame(0, DB::table('roll_records')->count());
    }

    public function test_old_chat_roll_is_idempotent_and_private_message_references_only_its_own_roll(): void
    {
        [$gm, $player, $other, , $scene] = $this->fixture();
        Sanctum::actingAs($player);
        $url = '/api/scenes/'.$scene->id;
        $data = ['text' => '/roll d20+3', 'requestId' => (string) Str::uuid(), 'label' => 'Percepção'];
        $roll = $this->postJson($url.'/chat', $data)->assertOk()->json('message');
        $this->assertSame($roll['id'], $roll['rollId']);
        $this->postJson($url.'/chat', $data)->assertOk()->assertJsonPath('message.id', $roll['id']);
        $this->assertCount(1, $scene->fresh()->state['chat']);

        $whisper = ['audience' => 'user', 'recipientUserId' => $other->id, 'text' => '/roll d20+2', 'requestId' => (string) Str::uuid()];
        $private = $this->postJson($url.'/private-messages', $whisper)->assertCreated()->json('message');
        $this->assertNotNull($private['roll_id']);
        $this->postJson($url.'/private-messages', $whisper)->assertOk()->assertJsonPath('message.id', $private['id']);
        $this->assertCount(1, $scene->fresh()->state['chat']);
        $this->assertDatabaseCount('private_messages', 1);
        $this->assertDatabaseCount('roll_records', 2);
        Sanctum::actingAs($gm);
        $this->assertNotContains($private['roll_id'], array_column($this->getJson($url.'/rolls')->assertOk()->json('rolls'), 'id'));
        Sanctum::actingAs($other);
        $this->assertContains($private['roll_id'], array_column($this->getJson($url.'/rolls')->assertOk()->json('rolls'), 'id'));
    }

    public function test_roll_history_is_paginated_and_keeps_server_evaluation_for_both_editions(): void
    {
        [, $owner, , , $scene] = $this->fixture();
        Sanctum::actingAs($owner);
        $url = '/api/scenes/'.$scene->id.'/rolls';
        foreach (['5e-2014', '5e-2024'] as $edition) {
            $scene->campaign->update(['ruleset' => $edition]);
            $this->postJson($url, [
                'requestId' => (string) Str::uuid(), 'formula' => 'd20+2', 'mode' => 'disadvantage',
                'context' => 'save', 'label' => 'Destreza',
            ])->assertOk()->assertJsonPath('roll.formula', '2d20kl1+2')
                ->assertJsonPath('roll.mode', 'disadvantage')
                ->assertJsonPath('roll.edition', $edition);
        }
        $template = DB::table('roll_records')->first();
        for ($i = 0; $i < 100; $i++) {
            $row = (array) $template;
            $row['id'] = (string) Str::uuid();
            $row['request_id'] = (string) Str::uuid();
            DB::table('roll_records')->insert($row);
        }
        $page1 = $this->getJson($url)->assertOk()->assertJsonPath('page', 1)
            ->assertJsonPath('hasMore', true)->assertJsonCount(100, 'rolls')->json('rolls');
        $page2 = $this->getJson($url.'?page=2')->assertOk()->assertJsonPath('page', 2)
            ->assertJsonPath('hasMore', false)->assertJsonCount(2, 'rolls')->json('rolls');
        $this->assertCount(102, array_unique([...array_column($page1, 'id'), ...array_column($page2, 'id')]));
    }

    public function test_roll_table_uses_the_same_persisted_result_on_retry(): void
    {
        [, $owner, , , $scene] = $this->fixture();
        Sanctum::actingAs($owner);
        $table = RollTable::create([
            'campaign_id' => $scene->campaign_id, 'name' => 'Encontro', 'formula' => '1d6', 'enabled' => true,
            'entries' => [['min' => 1, 'max' => 6, 'label' => 'Resultado']], 'metadata' => [],
        ]);
        $payload = ['sceneId' => $scene->id, 'requestId' => (string) Str::uuid()];
        $first = $this->postJson('/api/roll-tables/'.$table->id.'/roll', $payload)->assertOk();
        $rollId = $first->json('record.roll_id');
        $this->assertSame($rollId, $first->json('roll.id'));
        $this->assertDatabaseHas('roll_records', ['id' => $rollId, 'scene_id' => $scene->id]);
        $this->postJson('/api/roll-tables/'.$table->id.'/roll', $payload)->assertOk()
            ->assertJsonPath('record.id', $first->json('record.id'))->assertJsonPath('roll.id', $rollId)
            ->assertJsonPath('roll.total', $first->json('roll.total'))->assertJsonPath('roll.replayed', true);
        $this->assertDatabaseCount('roll_table_rolls', 1);
        $this->assertDatabaseCount('roll_records', 1);
    }
}
