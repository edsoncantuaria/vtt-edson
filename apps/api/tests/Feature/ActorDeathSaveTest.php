<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use App\Support\Dnd\DeathSaveRules;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActorDeathSaveTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Mesa']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Cena', 'published' => true, 'state' => SceneStateFactory::empty()]);
        foreach ([[$gm, 'gm'], [$player, 'player'], [$other, 'player']] as [$user, $role]) {
            SceneMember::create(['scene_id' => $scene->id, 'user_id' => $user->id, 'role' => $role]);
        }
        $system = ActorStateFactory::character();
        $system['hp']['value'] = 0;
        $actor = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => $player->id, 'name' => 'Hero', 'type' => 'character', 'system' => $system]);

        return [$gm, $player, $other, $campaign, $scene, $actor];
    }

    public function test_owner_rolls_once_and_retry_does_not_duplicate_chat_or_death_save(): void
    {
        [, $player, , , $scene, $actor] = $this->fixture();
        Sanctum::actingAs($player);
        $url = '/api/scenes/'.$scene->id.'/actors/'.$actor->id.'/death-save';
        $requestId = (string) Str::uuid();
        $before = $actor->system;
        $first = $this->postJson($url, ['requestId' => $requestId])->assertOk()->assertJsonPath('alreadyRolled', false)
            ->assertJsonPath('message.sourceActorId', $actor->id);
        $this->assertSame('roll', $first->json('message.type'));
        $this->assertSame('d20', $first->json('message.formula'));
        $expected = DeathSaveRules::resolve($before, $first->json('message.total'))['system'];
        $this->assertSame($expected['deathSaves'], $actor->fresh()->system['deathSaves']);
        $this->assertSame($expected['hp']['value'], $actor->fresh()->system['hp']['value']);
        $this->getJson('/api/actors/'.$actor->id)->assertOk()
            ->assertJsonPath('actor.system.deathSaves', $expected['deathSaves']);
        $this->getJson('/api/scenes/'.$scene->id)->assertOk()
            ->assertJsonPath('scene.state.chat.0.id', $first->json('message.id'));
        $this->assertCount(1, $scene->fresh()->state['chat']);
        $after = $actor->fresh()->system;
        $this->postJson($url, ['requestId' => $requestId])->assertOk()->assertJsonPath('alreadyRolled', true)
            ->assertJsonPath('message.id', $first->json('message.id'));
        $this->assertCount(1, $scene->fresh()->state['chat']);
        $this->assertSame($after, $actor->fresh()->system);
        $this->assertDatabaseCount('actor_death_save_rolls', 1);
    }

    public function test_non_owner_foreign_scene_and_conscious_character_cannot_roll(): void
    {
        [$gm, $player, $other, $campaign, $scene, $actor] = $this->fixture();
        $url = '/api/scenes/'.$scene->id.'/actors/'.$actor->id.'/death-save';
        Sanctum::actingAs($other);
        $this->postJson($url, ['requestId' => (string) Str::uuid()])->assertForbidden();
        Sanctum::actingAs($player);
        $conscious = $actor->system;
        $conscious['hp']['value'] = 5;
        $actor->update(['system' => $conscious]);
        $this->postJson($url, ['requestId' => (string) Str::uuid()])->assertUnprocessable();
        $foreign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Outra']);
        $otherScene = Scene::create(['campaign_id' => $foreign->id, 'name' => 'Outra', 'published' => true, 'state' => SceneStateFactory::empty()]);
        SceneMember::create(['scene_id' => $otherScene->id, 'user_id' => $player->id, 'role' => 'player']);
        $this->postJson('/api/scenes/'.$otherScene->id.'/actors/'.$actor->id.'/death-save', ['requestId' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('actor_death_save_rolls', 0);
        $this->assertSame($campaign->id, $actor->fresh()->campaign_id);
    }
}
