<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
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

class CustomActionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Homebrew']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Cena', 'published' => true, 'state' => SceneStateFactory::empty()]);
        foreach ([[$gm, 'gm'], [$player, 'player']] as [$user, $role]) {
            SceneMember::create(['scene_id' => $scene->id, 'user_id' => $user->id, 'role' => $role]);
        }
        $system = ActorStateFactory::character();
        $system['actions'] = [
            ['id' => 'torch', 'name' => 'Ataque com tocha', 'kind' => 'attack', 'origin' => 'Mesa · homebrew', 'visibility' => 'public', 'attackFormula' => '1d20+2', 'damageFormula' => '1d4', 'damageType' => 'fire'],
            ['id' => 'secret', 'name' => 'Segredo do mestre', 'kind' => 'feature', 'origin' => 'Notas GM', 'visibility' => 'gm', 'attackFormula' => '1d20+4'],
        ];
        $actor = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => $player->id, 'type' => 'character', 'name' => 'Aventureiro', 'system' => $system, 'shared' => true]);

        return [$gm, $player, $campaign, $scene, $actor];
    }

    public function test_custom_action_records_immutable_snapshot_revision_and_retry_after_sheet_edit(): void
    {
        [$gm, , , $scene, $actor] = $this->fixture();
        Sanctum::actingAs($gm);
        $url = '/api/scenes/'.$scene->id.'/actions';
        $request = ['actorId' => $actor->id, 'actionId' => 'torch', 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($url, $request)->assertOk()
            ->assertJsonPath('message.actionOrigin', 'Mesa · homebrew')->json('message');
        $record = DB::table('action_records')->first();
        $this->assertSame('1d20+2', json_decode($record->action_snapshot, true)['attackFormula']);
        $this->assertSame('Mesa · homebrew', json_decode($record->action_snapshot, true)['origin']);
        $this->assertSame($message['actionRevision'], $record->actor_revision);
        $this->assertDatabaseCount('roll_records', 2);

        $system = $actor->fresh()->system;
        $system['actions'][0]['attackFormula'] = '1d20+9';
        $this->patchJson('/api/actors/'.$actor->id, ['revision' => $actor->fresh()->revision, 'system' => $system])->assertOk();
        $this->postJson($url, $request)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->assertDatabaseCount('action_records', 1);
        $this->assertDatabaseCount('roll_records', 2);
        $this->assertSame('1d20+2', json_decode(DB::table('action_records')->first()->action_snapshot, true)['attackFormula']);
    }

    public function test_gm_only_action_is_hidden_from_players_and_cannot_be_executed_or_deleted_by_sheet_update(): void
    {
        [$gm, $player, $campaign, $scene, $actor] = $this->fixture();
        Sanctum::actingAs($gm);
        $url = '/api/scenes/'.$scene->id.'/actions';
        $message = $this->postJson($url, ['actorId' => $actor->id, 'actionId' => 'secret', 'requestId' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('message.visibility', 'gm')->json('message');
        $this->assertDatabaseHas('roll_records', ['visibility' => 'gm', 'actor_id' => $actor->id]);
        $this->getJson('/api/actors/'.$actor->id)->assertJsonCount(2, 'actor.system.actions');
        Sanctum::actingAs($player);
        $this->getJson('/api/actors/'.$actor->id)->assertOk()->assertJsonCount(1, 'actor.system.actions');
        $this->getJson('/api/campaigns/'.$campaign->id.'/actors')->assertOk()->assertJsonCount(1, 'actors.0.system.actions');
        $this->getJson('/api/actors/'.$actor->id.'/export')->assertOk()->assertJsonCount(1, 'system.actions');
        $this->getJson('/api/scenes/'.$scene->id)->assertOk()->assertJsonCount(0, 'scene.state.chat');
        $this->getJson('/api/scenes/'.$scene->id.'/actions')->assertOk()->assertJsonCount(0, 'messages');
        $this->getJson('/api/scenes/'.$scene->id.'/rolls')->assertOk()->assertJsonCount(0, 'rolls');
        $this->postJson($url, ['actorId' => $actor->id, 'actionId' => 'secret', 'requestId' => (string) Str::uuid()])->assertForbidden();
        $this->getJson('/api/scenes/'.$scene->id.'/damage/'.$message['id'].'?actorId='.$actor->id)->assertNotFound();
        $this->getJson('/api/scenes/'.$scene->id.'/actions/'.$message['id'].'/heal?actorId='.$actor->id)->assertNotFound();

        $public = $this->getJson('/api/actors/'.$actor->id)->json('actor');
        $public['system']['bio']['notes'] = 'Nota do personagem';
        $this->patchJson('/api/actors/'.$actor->id, ['revision' => $public['revision'], 'system' => $public['system']])->assertOk();
        $this->assertCount(2, $actor->fresh()->system['actions']);
        $public['system']['actions'][0]['visibility'] = 'gm';
        $this->patchJson('/api/actors/'.$actor->id, ['revision' => $actor->fresh()->revision, 'system' => $public['system']])->assertForbidden();
        $this->assertSame('secret', $actor->fresh()->system['actions'][1]['id']);
    }

    public function test_invalid_formula_and_duplicate_action_identifiers_are_rejected_before_commit(): void
    {
        [$gm, , , , $actor] = $this->fixture();
        Sanctum::actingAs($gm);
        $original = $actor->system;
        $invalid = $original;
        $invalid['actions'][0]['healingFormula'] = 'eval(42)';
        $this->patchJson('/api/actors/'.$actor->id, ['revision' => $actor->revision, 'system' => $invalid])->assertUnprocessable();
        $duplicates = $original;
        $duplicates['actions'][1]['id'] = $duplicates['actions'][0]['id'];
        $this->patchJson('/api/actors/'.$actor->id, ['revision' => $actor->revision, 'system' => $duplicates])->assertUnprocessable();
        $this->assertSame($original['actions'], $actor->fresh()->system['actions']);
    }

    public function test_player_can_add_and_execute_a_configured_homebrew_potion_without_client_side_healing(): void
    {
        [, $player, , $scene, $actor] = $this->fixture();
        Sanctum::actingAs($player);
        $system = $this->getJson('/api/actors/'.$actor->id)->assertOk()->json('actor.system');
        $system['hp']['value'] = 4;
        $system['actions'][] = [
            'id' => 'potion-copy', 'name' => 'Poção personalizada', 'kind' => 'item',
            'origin' => 'Receita da mesa', 'visibility' => 'public', 'target' => 'self',
            'healingFormula' => '2d4+2', 'economy' => 'action',
        ];
        $revision = $actor->fresh()->revision;
        $this->patchJson('/api/actors/'.$actor->id, ['revision' => $revision, 'system' => $system])
            ->assertOk()->assertJsonPath('actor.system.actions.1.name', 'Poção personalizada');
        $this->assertSame(4, $actor->fresh()->system['hp']['value']);
        $this->postJson('/api/scenes/'.$scene->id.'/actions', [
            'actorId' => $actor->id, 'actionId' => 'potion-copy',
            'targetActorIds' => [$actor->id], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('message.pipeline.0.kind', 'heal')
            ->assertJsonPath('message.actionOrigin', 'Receita da mesa');
        $this->assertSame(4, $actor->fresh()->system['hp']['value']);
        $this->assertDatabaseHas('roll_records', ['actor_id' => $actor->id, 'context' => 'heal']);
    }
}
