<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CatalogEntry;
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

class ActorAdvancementTest extends TestCase
{
    use RefreshDatabase;

    private function initial(): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Test', 'ruleset' => '5e-2014']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Stage', 'state' => SceneStateFactory::empty()]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $gm->id, 'role' => 'gm']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $player->id, 'role' => 'player']);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $other->id, 'role' => 'player']);
        $class = CatalogEntry::create(['slug' => 'fighter', 'kind' => 'classes', 'name' => 'Fighter', 'source' => 'PHB', 'edition' => '5e-2014',
            'data' => ['raw' => ['hd' => ['faces' => 10]], 'levelFeatures' => [['name' => 'Action Surge', 'level' => 2]]]]);
        $system = ActorStateFactory::character();
        $system['bio']['class'] = 'Fighter';
        $system['progression'] = ['classes' => [['classId' => $class->id, 'name' => 'Fighter', 'source' => 'PHB', 'level' => 1, 'hitDie' => 10]], 'subclass' => null];
        $system['preparation'] = ['classId' => $class->id, 'source' => 'PHB', 'edition' => '5e-2014', 'tasks' => []];
        $actor = Actor::create(['campaign_id' => $campaign->id, 'owner_user_id' => $player->id, 'type' => 'character', 'name' => 'Hero', 'system' => $system]);

        return [$gm, $player, $other, $campaign, $actor, $class];
    }

    private function proposal(Actor $actor, CatalogEntry $class): array
    {
        $system = $actor->system;
        $system['bio']['level'] = 2;
        $system['bio']['class'] = 'Fighter 2';
        $system['progression']['classes'][0]['level'] = 2;
        $system['hitDice'] = ['die' => 10, 'total' => 2, 'used' => 0];
        $system['hitDice']['total'] = 2;
        $system['hp']['max'] = 16;
        $system['hp']['value'] = 16;
        $system['features'][] = ['id' => 'surge', 'name' => 'Action Surge', 'level' => 2, 'source' => 'PHB'];

        return ['requestId' => (string) Str::uuid(), 'revision' => $actor->revision ?? 0,
            'classId' => $class->id, 'targetLevel' => 2, 'system' => $system];
    }

    public function test_preview_is_read_only_and_commit_is_atomic_idempotent_and_audited(): void
    {
        [$gm, $player, , , $actor, $class] = $this->initial();
        $path = '/api/actors/'.$actor->id.'/advancements';
        $payload = $this->proposal($actor, $class);
        Sanctum::actingAs($player);
        $this->postJson($path, [...$payload, 'preview' => true])->assertOk()->assertJsonPath('preview.level.1', 2);
        $this->assertSame(1, $actor->fresh()->system['bio']['level']);
        $this->assertDatabaseCount('actor_advancements', 0);
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('alreadyApplied', false)->assertJsonPath('actor.system.bio.level', 2);
        $this->assertDatabaseCount('actor_advancements', 1);
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('alreadyApplied', true);
        $this->assertDatabaseCount('actor_advancements', 1);
        $this->postJson($path, [...$payload, 'targetLevel' => 3])->assertStatus(409);
        $this->postJson($path, [...$payload, 'requestId' => (string) Str::uuid()])->assertStatus(409);
        $record = DB::table('actor_advancements')->first();
        $this->assertSame($player->id, $record->user_id);
        $this->assertSame(1, $record->from_level);
        $this->assertSame(2, $record->to_level);
        Sanctum::actingAs($gm);
        $this->getJson('/api/actors/'.$actor->id)->assertOk()->assertJsonPath('actor.system.hp.max', 16);
    }

    public function test_preview_cannot_commit_after_another_client_changes_the_sheet(): void
    {
        [, $player, , , $actor, $class] = $this->initial();
        Sanctum::actingAs($player);
        $path = '/api/actors/'.$actor->id.'/advancements';
        $proposal = $this->proposal($actor, $class);

        $this->postJson($path, [...$proposal, 'preview' => true])->assertOk();
        $updated = $actor->fresh()->system;
        $updated['hp']['temp'] = 5;
        $this->patchJson('/api/actors/'.$actor->id, [
            'revision' => $actor->fresh()->revision,
            'system' => $updated,
        ])->assertOk();

        $this->postJson($path, $proposal)->assertStatus(409);
        $this->assertSame(1, $actor->fresh()->system['bio']['level']);
        $this->assertSame(5, $actor->fresh()->system['hp']['temp']);
        $this->assertDatabaseCount('actor_advancements', 0);
    }

    public function test_permission_cross_edition_and_unrelated_state_tampering_are_rejected(): void
    {
        [$gm, $player, $other, $campaign, $actor, $class] = $this->initial();
        $path = '/api/actors/'.$actor->id.'/advancements';
        $payload = $this->proposal($actor, $class);
        Sanctum::actingAs($other);
        $this->postJson($path, $payload)->assertForbidden();
        Sanctum::actingAs($player);
        $bad = $payload;
        $bad['system']['inventory'][] = ['id' => 'free', 'name' => 'Free loot', 'quantity' => 1];
        $this->postJson($path, $bad)->assertUnprocessable();
        $bad = $payload;
        $bad['system']['hp']['max'] = 100;
        $this->postJson($path, $bad)->assertUnprocessable();
        $foreign = CatalogEntry::create(['slug' => 'fighter-2024', 'kind' => 'classes', 'name' => 'Fighter', 'source' => 'XPHB', 'edition' => '5e-2024', 'data' => []]);
        $this->postJson($path, [...$payload, 'classId' => $foreign->id])->assertUnprocessable();
        $this->assertDatabaseCount('actor_advancements', 0);
        $this->assertSame(1, $actor->fresh()->system['bio']['level']);
        $this->assertSame('gm', $campaign->roleFor($gm));
    }

    public function test_asi_requires_a_real_feature_and_applies_retroactive_constitution_hp(): void
    {
        [, $player, , , $actor, $class] = $this->initial();
        $catalog = $class->data;
        $catalog['levelFeatures'][] = ['name' => 'Ability Score Improvement', 'level' => 4];
        $class->update(['data' => $catalog]);
        $system = $actor->system;
        $system['bio']['level'] = 3;
        $system['bio']['class'] = 'Fighter 3';
        $system['progression']['classes'][0]['level'] = 3;
        $system['hitDice'] = ['die' => 10, 'total' => 3, 'used' => 0];
        $system['hp'] = ['value' => 24, 'max' => 24, 'temp' => 0];
        $actor->update(['system' => $system]);
        $actor->refresh();
        $proposal = $this->proposal($actor, $class);
        $proposal['targetLevel'] = 4;
        $proposal['system']['bio']['level'] = 4;
        $proposal['system']['bio']['class'] = 'Fighter 4';
        $proposal['system']['progression']['classes'][0]['level'] = 4;
        $proposal['system']['hitDice']['total'] = 4;
        $proposal['system']['hp']['value'] = 34;
        $proposal['system']['hp']['max'] = 34;
        $proposal['system']['abilities']['con']['score'] = 12;
        $proposal['system']['features'][] = ['id' => 'asi', 'name' => 'Ability Score Improvement', 'source' => 'PHB', 'level' => 4];
        $path = '/api/actors/'.$actor->id.'/advancements';
        Sanctum::actingAs($player);
        $this->postJson($path, $proposal)->assertUnprocessable();
        $this->postJson($path, [...$proposal, 'asi' => ['con' => 2]])
            ->assertOk()->assertJsonPath('actor.system.hp.max', 34)->assertJsonPath('actor.system.abilities.con.score', 12);
        $this->assertDatabaseHas('actor_advancements', ['actor_id' => $actor->id, 'from_level' => 3, 'to_level' => 4]);
    }

    public function test_subclass_is_required_at_eligible_level_and_multiclass_checks_prerequisites(): void
    {
        [, $player, , , $actor, $class] = $this->initial();
        $champion = CatalogEntry::create(['slug' => 'champion', 'kind' => 'subclasses', 'name' => 'Champion', 'source' => 'PHB', 'edition' => '5e-2014',
            'data' => ['raw' => ['className' => 'Fighter', 'classSource' => 'PHB', 'subclassFeatures' => ['Champion|Fighter|PHB|Champion|PHB|3']]]]);
        $system = $actor->system;
        $system['bio']['level'] = 2;
        $system['bio']['class'] = 'Fighter 2';
        $system['progression']['classes'][0]['level'] = 2;
        $system['hitDice'] = ['die' => 10, 'total' => 2, 'used' => 0];
        $system['hp'] = ['value' => 16, 'max' => 16, 'temp' => 0];
        $actor->update(['system' => $system]);
        $actor->refresh();
        $proposal = $this->proposal($actor, $class);
        $proposal['targetLevel'] = 3;
        $proposal['system']['bio']['level'] = 3;
        $proposal['system']['bio']['class'] = 'Fighter 3';
        $proposal['system']['progression']['classes'][0]['level'] = 3;
        $proposal['system']['hitDice']['total'] = 3;
        $proposal['system']['hp']['max'] = 22;
        $proposal['system']['hp']['value'] = 22;
        Sanctum::actingAs($player);
        $path = '/api/actors/'.$actor->id.'/advancements';
        $this->postJson($path, $proposal)->assertUnprocessable();
        $this->assertSame(2, $actor->fresh()->system['bio']['level']);
        $wizard = CatalogEntry::create(['slug' => 'wizard', 'kind' => 'classes', 'name' => 'Wizard', 'source' => 'PHB', 'edition' => '5e-2014',
            'data' => ['raw' => ['hd' => ['faces' => 6], 'multiclassing' => ['requirements' => ['int' => 13]]]]]);
        $multiclass = $proposal;
        $multiclass['requestId'] = (string) Str::uuid();
        $multiclass['classId'] = $wizard->id;
        $multiclass['system']['bio']['class'] = 'Fighter 2 / Wizard 1';
        $multiclass['system']['progression']['classes'] = [
            ['classId' => $class->id, 'name' => 'Fighter', 'source' => 'PHB', 'level' => 2, 'hitDie' => 10],
            ['classId' => $wizard->id, 'name' => 'Wizard', 'source' => 'PHB', 'level' => 1, 'hitDie' => 6],
        ];
        $multiclass['system']['hp'] = ['value' => 20, 'max' => 20, 'temp' => 0];
        $this->postJson($path, $multiclass)->assertUnprocessable();
        $proposal['system']['progression']['subclasses'] = [[
            'subclassId' => $champion->id, 'name' => 'Champion', 'source' => 'PHB',
            'className' => 'Fighter', 'classSource' => 'PHB',
        ]];
        $this->postJson($path, [...$proposal, 'subclassId' => $champion->id])
            ->assertOk()->assertJsonPath('actor.system.progression.subclasses.0.subclassId', $champion->id);
    }
}
