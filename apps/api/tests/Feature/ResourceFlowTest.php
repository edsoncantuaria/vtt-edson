<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CatalogEntry;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResourceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function room(string $edition = '5e-2024'): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Resource validation', 'ruleset' => $edition])->assertCreated()->json();
        $system = ActorStateFactory::character();
        $system['inspiration'] = true;
        $system['spells']['slots'] = ['1' => ['max' => 2, 'used' => 0]];
        $system['resources'] = [['id' => 'rage', 'name' => 'Fúria', 'kind' => 'rage', 'source' => 'Barbarian · '.$edition,
            'edition' => $edition, 'max' => 4, 'used' => 3, 'reset' => 'long', 'defaultCost' => 1]];
        $system['actions'] = [['id' => 'rage-cast', 'name' => 'Ritual de Fúria', 'kind' => 'spell',
            'spellSlotLevel' => 1, 'resourceId' => 'rage', 'damageFormula' => '1d4', 'damageType' => 'fire']];
        $actor = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'type' => 'character', 'name' => 'Hero', 'system' => $system]);
        $other = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $gm->id,
            'type' => 'character', 'name' => 'Foreign', 'system' => ActorStateFactory::character()]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();

        return [$gm, $player, $room, $actor, $other, '/api/scenes/'.$room['scene']['id']];
    }

    public function test_authorized_adjustment_is_atomic_bounded_idempotent_and_audited_with_owned_document_charges(): void
    {
        [$gm, $player, , $actor, $other] = $this->room();
        $document = ActorDocument::create(['actor_id' => $actor->id, 'kind' => 'item', 'name' => 'Wand',
            'charges' => ['max' => 3, 'value' => 2, 'reset' => 'long']]);
        $foreign = ActorDocument::create(['actor_id' => $other->id, 'kind' => 'item', 'name' => 'Other wand',
            'charges' => ['max' => 3, 'value' => 3, 'reset' => 'long']]);
        $path = '/api/actors/'.$actor->id.'/resources/adjust';
        $first = ['requestId' => (string) Str::uuid(), 'resourceId' => 'rage', 'current' => 0];
        $this->getJson('/api/actors/'.$actor->id.'/resources')->assertOk()->assertJsonPath('pools.0.id', 'slot:1')
            ->assertJsonPath('pools.1.id', 'inspiration')->assertJsonPath('pools.2.current', 1)
            ->assertJsonPath('pools.3.id', 'document:'.$document->id);
        $this->postJson($path, $first)->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('actor.system.resources.0.used', 4);
        $this->postJson($path, $first)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($path, [...$first, 'current' => 1])->assertStatus(409);
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'rage', 'current' => 3])->assertForbidden();
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'document:'.$foreign->id, 'current' => 2])->assertUnprocessable();
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'document:'.$document->id, 'current' => 1])
            ->assertOk();
        $this->assertSame(1, ActorDocument::findOrFail($document->id)->charges['value']);
        $this->assertSame(3, ActorDocument::findOrFail($foreign->id)->charges['value']);
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'inspiration', 'current' => 0])
            ->assertOk()->assertJsonPath('actor.system.inspiration', false);
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'inspiration', 'current' => 1])
            ->assertForbidden();
        $this->assertDatabaseCount('actor_resource_events', 3);
        Sanctum::actingAs($gm);
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'rage', 'current' => 3])
            ->assertUnprocessable();
        $this->postJson($path, ['requestId' => (string) Str::uuid(), 'resourceId' => 'rage', 'current' => 3,
            'reason' => 'Adjudicação do mestre'])->assertOk()->assertJsonPath('actor.system.resources.0.used', 1);
        $this->getJson('/api/actors/'.$actor->id)->assertOk()
            ->assertJsonPath('actor.resourcePools.2.current', 3)
            ->assertJsonPath('actor.resourcePools.3.current', 1)
            ->assertJsonPath('actor.resourcePools.2.source', 'Barbarian · 5e-2024');
        $this->getJson('/api/actors/'.$actor->id.'/resources')->assertOk()->assertJsonCount(4, 'events')
            ->assertJsonPath('events.0.reason', 'Adjudicação do mestre');
        $this->assertDatabaseHas('actor_resource_events', ['actor_id' => $actor->id, 'user_id' => $gm->id,
            'event' => 'adjust', 'reason' => 'Adjudicação do mestre']);
        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);
        $this->getJson('/api/actors/'.$actor->id.'/resources')->assertForbidden();
        $this->postJson($path, [...$first, 'requestId' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_combined_action_quotes_all_costs_before_dice_and_retries_undo_without_extra_consumption(): void
    {
        [$gm, $player, , $actor, , $base] = $this->room();
        Sanctum::actingAs($player);
        $actor->refresh();
        $system = $actor->system;
        $system['actions'][0]['resourceCost'] = 2;
        $actor->update(['system' => $system]);
        $request = ['actorId' => $actor->id, 'actionId' => 'rage-cast', 'requestId' => (string) Str::uuid()];
        $this->postJson($base.'/actions', $request)->assertUnprocessable()->assertJsonPath('message', 'Não há usos suficientes de Fúria.');
        $this->assertDatabaseCount('roll_records', 0);
        $this->assertDatabaseCount('actor_resource_events', 0);
        $this->assertSame(0, $actor->fresh()->system['spells']['slots']['1']['used']);
        $this->assertSame(3, $actor->fresh()->system['resources'][0]['used']);
        $system['actions'][0]['resourceCost'] = 1;
        $actor->update(['system' => $system]);
        $request['requestId'] = (string) Str::uuid();
        $message = $this->postJson($base.'/actions', $request)->assertOk()
            ->assertJsonPath('actor.system.spells.slots.1.used', 1)
            ->assertJsonPath('actor.system.resources.0.used', 4)->json('message');
        $this->postJson($base.'/actions', $request)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->assertDatabaseCount('roll_records', 1);
        $this->assertDatabaseCount('actor_resource_events', 1);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $actor->id])->assertOk()
            ->assertJsonPath('actor.system.spells.slots.1.used', 0)->assertJsonPath('actor.system.resources.0.used', 3);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $actor->id])->assertOk();
        $this->assertDatabaseCount('actor_resource_events', 2);
        Sanctum::actingAs($gm);
        $this->getJson('/api/actors/'.$actor->id.'/resources')->assertOk()->assertJsonCount(2, 'events');
    }

    public function test_2024_partial_rest_once_per_request_and_2014_rage_difference_are_persistent(): void
    {
        [, $player, , $actor] = $this->room('5e-2024');
        Sanctum::actingAs($player);
        $key = (string) Str::uuid();
        $path = '/api/actors/'.$actor->id.'/rest';
        $this->postJson($path, ['rest' => 'short', 'requestId' => $key])->assertOk()
            ->assertJsonPath('actor.system.resources.0.used', 2)->assertJsonPath('actor.system.spells.slots.1.used', 0);
        $this->postJson($path, ['rest' => 'short', 'requestId' => $key])->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('actor.system.resources.0.used', 2);
        $this->postJson($path, ['rest' => 'long', 'requestId' => $key])->assertStatus(409);
        $this->postJson($path, ['rest' => 'long', 'requestId' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('actor.system.resources.0.used', 0);
        $this->assertDatabaseCount('actor_resource_events', 2);
        [, $legacyPlayer, , $legacyActor] = $this->room('5e-2014');
        Sanctum::actingAs($legacyPlayer);
        $this->postJson('/api/actors/'.$legacyActor->id.'/rest', ['rest' => 'short', 'requestId' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('actor.system.resources.0.used', 3);
    }

    public function test_inspiration_and_slot_only_actions_use_same_atomic_consumer_and_undo(): void
    {
        [, $player, , $actor, , $base] = $this->room();
        Sanctum::actingAs($player);
        $system = $actor->system;
        $system['actions'][] = ['id' => 'inspired', 'name' => 'Inspiração', 'kind' => 'feature', 'resourceId' => 'inspiration'];
        $system['actions'][] = ['id' => 'slot-only', 'name' => 'Canalizar espaço', 'kind' => 'feature', 'spellSlotLevel' => 1];
        $actor->update(['system' => $system]);
        $one = $this->postJson($base.'/actions', ['actorId' => $actor->id, 'actionId' => 'inspired',
            'requestId' => (string) Str::uuid()])->assertOk()->assertJsonPath('actor.system.inspiration', false)->json('message.id');
        $this->postJson($base.'/actions', ['actorId' => $actor->id, 'actionId' => 'inspired',
            'requestId' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('message', 'Inspiração indisponível nesta ficha.');
        $this->postJson($base.'/actions/'.$one.'/undo', ['actorId' => $actor->id])->assertOk()
            ->assertJsonPath('actor.system.inspiration', true);
        $slot = $this->postJson($base.'/actions', ['actorId' => $actor->id, 'actionId' => 'slot-only',
            'requestId' => (string) Str::uuid()])->assertOk()->assertJsonPath('actor.system.spells.slots.1.used', 1)->json('message.id');
        $this->postJson($base.'/actions/'.$slot.'/undo', ['actorId' => $actor->id])->assertOk()
            ->assertJsonPath('actor.system.spells.slots.1.used', 0);
        $this->assertDatabaseCount('roll_records', 0);
        $this->assertDatabaseCount('actor_resource_events', 4);
    }

    public function test_level_up_uses_only_explicit_class_resource_scaling_without_replenishing_spent_uses(): void
    {
        [$gm, $player, $room, $actor] = $this->room('5e-2014');
        $class = CatalogEntry::create(['slug' => 'fighter-resource', 'kind' => 'classes', 'name' => 'Fighter',
            'source' => 'PHB', 'edition' => '5e-2014',
            'data' => ['raw' => ['hd' => ['faces' => 10]], 'levelFeatures' => [['name' => 'Action Surge', 'level' => 2]]]]);
        $system = $actor->fresh()->system;
        $system['bio']['class'] = 'Fighter';
        $system['progression'] = ['classes' => [['classId' => $class->id, 'name' => 'Fighter', 'source' => 'PHB', 'level' => 1, 'hitDie' => 10]], 'subclass' => null];
        $system['preparation'] = ['classId' => $class->id, 'source' => 'PHB', 'edition' => '5e-2014', 'tasks' => []];
        $system['resources'][0]['scaling'] = ['className' => 'Fighter', 'byLevel' => ['1' => 4, '2' => 6]];
        $actor->update(['system' => $system]);
        $actor->refresh();
        $next = $actor->system;
        $next['bio']['level'] = 2;
        $next['bio']['class'] = 'Fighter 2';
        $next['progression']['classes'][0]['level'] = 2;
        $next['hitDice'] = ['die' => 10, 'total' => 2, 'used' => 0];
        $next['hp'] = ['value' => 16, 'max' => 16, 'temp' => 0];
        $next['features'][] = ['id' => 'surge', 'name' => 'Action Surge', 'source' => 'PHB', 'level' => 2];
        $payload = ['requestId' => (string) Str::uuid(), 'revision' => $actor->revision, 'classId' => $class->id,
            'targetLevel' => 2, 'system' => $next];
        Sanctum::actingAs($player);
        $this->postJson('/api/actors/'.$actor->id.'/advancements', [...$payload, 'preview' => true])->assertOk()
            ->assertJsonPath('preview.resources.1.0.max', 6)->assertJsonPath('preview.resources.1.0.used', 3);
        $this->assertSame(4, $actor->fresh()->system['resources'][0]['max']);
        $this->postJson('/api/actors/'.$actor->id.'/advancements', $payload)->assertOk()
            ->assertJsonPath('actor.system.resources.0.max', 6)->assertJsonPath('actor.system.resources.0.used', 3);
        $this->assertDatabaseHas('actor_resource_events', ['actor_id' => $actor->id, 'event' => 'level-up']);
        $this->postJson('/api/actors/'.$actor->id.'/advancements', $payload)->assertOk()->assertJsonPath('alreadyApplied', true);
        $this->assertDatabaseCount('actor_resource_events', 1);
    }

    public function test_resource_validation_rejects_cross_edition_duplicate_reserved_ids_and_invalid_recovery(): void
    {
        [, $player, , $actor] = $this->room();
        Sanctum::actingAs($player);
        $system = $actor->system;
        $path = '/api/actors/'.$actor->id;
        $update = fn (array $draft) => $this->patchJson($path, ['system' => $draft, 'revision' => $actor->fresh()->revision]);
        $bad = $system;
        $bad['resources'][0]['edition'] = '5e-2014';
        $update($bad)->assertUnprocessable();
        $bad = $system;
        $bad['resources'][] = $bad['resources'][0];
        $update($bad)->assertUnprocessable();
        $bad = $system;
        $bad['resources'][0]['id'] = 'inspiration';
        $update($bad)->assertUnprocessable();
        $bad = $system;
        $bad['resources'][0]['recovery'] = ['short' => 'twice', 'long' => 'full'];
        $update($bad)->assertUnprocessable();
        $bad = $system;
        $bad['resources'][0]['scaling'] = ['className' => 'Barbarian', 'byLevel' => ['21' => 9]];
        $update($bad)->assertUnprocessable();
        $bad = $system;
        $bad['spells']['slots']['1']['reset'] = 'minute';
        $update($bad)->assertUnprocessable();
        $this->assertSame(3, $actor->fresh()->system['resources'][0]['used']);
        $valid = $system;
        $valid['resources'][0]['recovery'] = ['short' => 'one', 'long' => 'full'];
        $valid['resources'][0]['scaling'] = ['className' => 'Barbarian', 'byLevel' => ['2' => 5]];
        $valid['spells']['slots']['1']['reset'] = 'short';
        $update($valid)->assertOk()->assertJsonPath('actor.system.resources.0.recovery.short', 'one')
            ->assertJsonPath('actor.system.resources.0.scaling.byLevel.2', 5)
            ->assertJsonPath('actor.system.spells.slots.1.reset', 'short');
    }
}
