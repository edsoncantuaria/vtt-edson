<?php

namespace Tests\Feature;

use App\Game\Dice\DiceRoller;
use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CatalogEntry;
use App\Models\Scene;
use App\Models\User;
use App\Support\Dnd\ActorDocumentService;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpellcastingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function encounter(string $edition = '5e-2024'): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Guided spellcasting', 'ruleset' => $edition])->assertCreated()->json();
        $system = ActorStateFactory::character();
        $system['bio']['class'] = 'Wizard';
        $system['spellcastingAbility'] = 'int';
        $system['progression'] = ['classes' => [['name' => 'Wizard', 'source' => $edition === '5e-2024' ? 'XPHB' : 'PHB', 'level' => 5]]];
        $system['spells']['slots'] = ['1' => ['max' => 3, 'used' => 0], '2' => ['max' => 2, 'used' => 0],
            '3' => ['max' => 2, 'used' => 0], '4' => ['max' => 1, 'used' => 0]];
        $caster = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'name' => 'Mage', 'type' => 'character', 'system' => $system]);
        $allyState = ActorStateFactory::character();
        $allyState['hp'] = ['value' => 1, 'max' => 20, 'temp' => 0];
        $ally = Actor::create(['campaign_id' => $room['campaign']['id'], 'owner_user_id' => $player->id,
            'name' => 'Ally', 'type' => 'character', 'system' => $allyState]);
        $enemyA = Actor::create(['campaign_id' => $room['campaign']['id'], 'name' => 'Orc', 'type' => 'monster',
            'system' => ActorStateFactory::monster()]);
        $enemyB = Actor::create(['campaign_id' => $room['campaign']['id'], 'name' => 'Goblin', 'type' => 'monster',
            'system' => ActorStateFactory::monster()]);
        $far = Actor::create(['campaign_id' => $room['campaign']['id'], 'name' => 'Far enemy', 'type' => 'monster',
            'system' => ActorStateFactory::monster()]);
        $scene = Scene::findOrFail($room['scene']['id']);
        $state = $scene->state;
        $state['tokens'] = [
            ['id' => 'caster', 'name' => 'Mage', 'x' => 0, 'y' => 0, 'size' => 1, 'actorId' => $caster->id, 'ownerUserId' => $player->id],
            ['id' => 'ally', 'name' => 'Ally', 'x' => 70, 'y' => 0, 'size' => 1, 'actorId' => $ally->id, 'ownerUserId' => $player->id],
            ['id' => 'orc', 'name' => 'Orc', 'x' => 280, 'y' => 0, 'size' => 1, 'actorId' => $enemyA->id, 'ownerUserId' => null],
            ['id' => 'goblin', 'name' => 'Goblin', 'x' => 350, 'y' => 0, 'size' => 1, 'actorId' => $enemyB->id, 'ownerUserId' => null],
            ['id' => 'far', 'name' => 'Far', 'x' => 2800, 'y' => 0, 'size' => 1, 'actorId' => $far->id, 'ownerUserId' => null],
        ];
        $scene->update(['state' => $state]);
        $raws = [
            ['magic-missile', 'Magic Missile', 1, 120, ['v' => true, 's' => true], []],
            ['fireball', 'Fireball', 3, 150, ['v' => true, 's' => true, 'm' => 'guano and sulfur'], []],
            ['cure-wounds', 'Cure Wounds', 1, 5, ['v' => true, 's' => true], []],
            ['detect-magic', 'Detect Magic', 1, 0, ['v' => true, 's' => true], ['ritual' => true]],
        ];
        $docs = [];
        foreach ($raws as [$slug, $name, $level, $range, $components, $meta]) {
            $entryData = ['spellLists' => [($edition === '5e-2024' ? 'XPHB' : 'PHB').':Wizard'],
                'raw' => ['components' => $components, 'range' => ['distance' => ['amount' => $range]], 'meta' => $meta]];
            if ($slug === 'detect-magic') {
                $entryData['raw']['duration'] = [['concentration' => true, 'duration' => ['amount' => 10, 'type' => 'minute']]];
                $entryData['integration']['automation']['actions'] = [[
                    'name' => 'Detect Magic', 'effect' => ['name' => 'Detection', 'target' => 'self',
                        'duration' => ['unit' => 'minutes', 'remaining' => 10], 'modifiers' => [], 'conditions' => []],
                ]];
            }
            $entry = CatalogEntry::create(['slug' => $slug.'-'.$edition, 'kind' => 'spells', 'name' => $name,
                'source' => $edition === '5e-2024' ? 'XPHB' : 'PHB', 'edition' => $edition, 'level' => $level,
                'data' => $entryData]);
            $docs[$slug] = ActorDocument::create(['actor_id' => $caster->id, 'catalog_entry_id' => $entry->id,
                'kind' => 'spell', 'name' => $name, 'slug' => $entry->slug, 'source' => $entry->source,
                'data' => ['id' => (string) Str::uuid(), 'name' => $name, 'slug' => $entry->slug, 'level' => $level],
                'prepared' => $slug !== 'detect-magic']);
        }
        app(ActorDocumentService::class)->syncLegacy($caster);
        $this->app->instance(DiceRoller::class, new DiceRoller(fn ($min, $max) => min(2, $max)));
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();

        return [$gm, $player, $scene, $caster, $ally, $enemyA, $enemyB, $far, $docs, '/api/scenes/'.$scene->id];
    }

    public function test_catalog_profiles_guard_preparation_edition_class_and_ritual_without_spending_on_preview(): void
    {
        [$gm, $player, , $caster, , , , , $docs, $base] = $this->encounter();
        $profiles = $this->getJson('/api/actors/'.$caster->id.'/spells')->assertOk()->assertJsonCount(4, 'spells')->json('spells');
        $this->assertSame('missiles', $profiles[0]['kind']);
        $this->assertSame('area-save', $profiles[1]['kind']);
        $this->assertSame(['v' => true, 's' => true, 'm' => 'guano and sulfur'], $profiles[1]['components']);
        $this->assertTrue($profiles[3]['canRitual']);
        $this->assertFalse($profiles[3]['canCast']);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['detect-magic']->id,
            'spellCast' => ['slotLevel' => 1], 'requestId' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(0, $caster->fresh()->system['spells']['slots']['1']['used']);
        $ritual = $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['detect-magic']->id,
            'spellCast' => ['ritual' => true], 'requestId' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('message.spellCast.ritual', true)->assertJsonPath('message.spellCast.slotLevel', null);
        $this->assertDatabaseHas('active_effects', ['actor_id' => $caster->id, 'name' => 'Detection']);
        $firstConcentrationId = $caster->fresh()->system['concentration']['id'];
        $this->assertNotEmpty($firstConcentrationId);
        $firstEffect = $caster->activeEffects()->firstOrFail();
        $this->assertSame($caster->id, $firstEffect->concentration_actor_id);
        $this->assertSame(0, $caster->fresh()->system['spells']['slots']['1']['used']);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['detect-magic']->id,
            'spellCast' => ['ritual' => true, 'slotLevel' => 1], 'requestId' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame('Detect Magic', $ritual->json('message.spellCast.name'));
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['detect-magic']->id,
            'spellCast' => ['ritual' => true], 'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $this->assertNotSame($firstConcentrationId, $caster->fresh()->system['concentration']['id']);
        $this->assertFalse($firstEffect->fresh()->active);
        $this->assertSame(0, $caster->fresh()->system['spells']['slots']['1']['used']);
        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);
        $this->getJson('/api/actors/'.$caster->id.'/spells')->assertForbidden();
        Sanctum::actingAs($gm);
        $docs['magic-missile']->catalogEntry->update(['edition' => '5e-2014']);
        $this->getJson('/api/actors/'.$caster->id.'/spells')->assertJsonCount(3, 'spells');
    }

    public function test_magic_missile_allocates_independent_darts_to_visible_targets_with_atomic_cost_and_targeted_damage(): void
    {
        [$gm, $player, , $caster, , $orc, $goblin, , $docs, $base] = $this->encounter();
        $payload = ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['magic-missile']->id,
            'targetTokenIds' => ['orc', 'goblin'], 'spellCast' => ['slotLevel' => 1,
                'missiles' => [['tokenId' => 'orc', 'count' => 2], ['tokenId' => 'goblin', 'count' => 1]]],
            'requestId' => (string) Str::uuid()];
        $this->postJson($base.'/actions', [...$payload, 'spellCast' => ['slotLevel' => 1,
            'missiles' => [['tokenId' => 'orc', 'count' => 1], ['tokenId' => 'goblin', 'count' => 1]]]])->assertUnprocessable();
        $this->assertDatabaseCount('roll_records', 0);
        $message = $this->postJson($base.'/actions', $payload)->assertOk()->assertJsonCount(3, 'message.rolls')
            ->assertJsonPath('actor.system.spells.slots.1.used', 1)
            ->assertJsonPath('message.spellCast.missiles.0.count', 2)->json('message');
        $this->postJson($base.'/actions', $payload)->assertOk()->assertJsonPath('message.id', $message['id']);
        $this->postJson($base.'/actions', [...$payload, 'spellCast' => ['slotLevel' => 2,
            'missiles' => [['tokenId' => 'orc', 'count' => 2], ['tokenId' => 'goblin', 'count' => 2]]]])->assertStatus(409);
        $this->assertDatabaseCount('roll_records', 3);
        Sanctum::actingAs($gm);
        $this->getJson($base.'/damage/'.$message['id'].'?actorId='.$orc->id)->assertOk()->assertJsonPath('preview.damage', 6);
        $this->getJson($base.'/damage/'.$message['id'].'?actorId='.$goblin->id)->assertOk()->assertJsonPath('preview.damage', 3);
        $this->postJson($base.'/damage/'.$message['id'], ['actorId' => $orc->id, 'confirmed' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 4);
        $this->postJson($base.'/damage/'.$message['id'], ['actorId' => $goblin->id, 'confirmed' => true])->assertOk()
            ->assertJsonPath('actor.system.hp.value', 7);
        $this->assertDatabaseCount('damage_applications', 2);
        $this->assertSame(4, $orc->fresh()->system['hp']['value']);
        $this->assertSame(7, $goblin->fresh()->system['hp']['value']);
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $caster->id])->assertStatus(409);
        foreach ([$orc, $goblin] as $victim) {
            $this->postJson($base.'/damage/'.$message['id'], ['actorId' => $victim->id, 'undo' => true])->assertOk();
        }
        $this->postJson($base.'/actions/'.$message['id'].'/undo', ['actorId' => $caster->id])->assertOk()
            ->assertJsonPath('actor.system.spells.slots.1.used', 0);
    }

    public function test_fireball_uses_authoritative_area_center_derived_victims_save_and_upcast(): void
    {
        [$gm, $player, , $caster, $ally, $orc, $goblin, $far, $docs, $base] = $this->encounter();
        $payload = ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['fireball']->id,
            'spellCast' => ['slotLevel' => 4, 'centerTokenId' => 'orc', 'componentsConfirmed' => true], 'requestId' => (string) Str::uuid()];
        $this->postJson($base.'/actions', [...$payload, 'spellCast' => ['slotLevel' => 4, 'centerTokenId' => 'far', 'componentsConfirmed' => true]])
            ->assertUnprocessable();
        $this->assertDatabaseCount('roll_records', 0);
        $message = $this->postJson($base.'/actions', $payload)->assertOk()->assertJsonPath('message.rolls.0.formula', '9d6')
            ->assertJsonPath('message.save.ability', 'dex')->assertJsonPath('message.save.effect', 'half')
            ->assertJsonPath('actor.system.spells.slots.4.used', 1)->json('message');
        $this->assertEqualsCanonicalizing([$caster->id, $ally->id], $message['targetActorIds']); // private NPC IDs are redacted from player chat
        $stored = json_decode(DB::table('action_records')->where('message_id', $message['id'])->value('message'), true);
        $this->assertEqualsCanonicalizing([$caster->id, $ally->id, $orc->id, $goblin->id], $stored['targetActorIds']);
        $this->assertNotContains($far->id, $message['targetActorIds']);
        $this->assertDatabaseCount('roll_records', 1);
        $this->postJson($base.'/actions', $payload)->assertOk()->assertJsonPath('message.id', $message['id']);
        Sanctum::actingAs($gm);
        $this->getJson($base.'/actions/'.$message['id'].'/save-batch')->assertOk()->assertJsonCount(4, 'rows');
        $this->assertSame(0, DB::table('damage_applications')->count());
        $this->assertSame(1, $ally->fresh()->system['hp']['value']);
    }

    public function test_cure_wounds_uses_edition_specific_upcast_ally_target_and_existing_healing_confirmation(): void
    {
        [$gm, $player, , $caster, $ally, , , , $docs, $base] = $this->encounter('5e-2024');
        $payload = ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['cure-wounds']->id,
            'targetTokenIds' => ['ally'], 'spellCast' => ['slotLevel' => 2], 'requestId' => (string) Str::uuid()];
        $message = $this->postJson($base.'/actions', $payload)->assertOk()
            ->assertJsonPath('message.rolls.0.formula', '4d8+0')
            ->assertJsonPath('actor.system.spells.slots.2.used', 1)->json('message');
        $this->assertSame(1, $ally->fresh()->system['hp']['value']);
        $this->postJson($base.'/actions/'.$message['id'].'/heal', ['actorId' => $ally->id, 'confirmed' => true])->assertOk();
        $this->assertSame(9, $ally->fresh()->system['hp']['value']);
        $this->postJson($base.'/actions', $payload)->assertOk()->assertJsonPath('message.id', $message['id']);
        [$gm14, $player14, , $caster14, , , , , $docs14, $base14] = $this->encounter('5e-2014');
        Sanctum::actingAs($player14);
        $this->postJson($base14.'/actions', ['actorId' => $caster14->id, 'actionId' => 'spell:'.$docs14['cure-wounds']->id,
            'targetTokenIds' => ['ally'], 'spellCast' => ['slotLevel' => 2], 'requestId' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('message.rolls.0.formula', '2d8+0');
    }

    public function test_missing_material_wrong_class_foreign_spell_exhausted_slot_and_verbal_block_fail_without_rolls_or_costs(): void
    {
        [, $player, , $caster, , $orc, , , $docs, $base] = $this->encounter();
        Sanctum::actingAs($player);
        $fire = ['actorId' => $caster->id, 'actionId' => 'spell:'.$docs['fireball']->id,
            'spellCast' => ['slotLevel' => 3, 'centerTokenId' => 'orc'], 'requestId' => (string) Str::uuid()];
        $this->postJson($base.'/actions', $fire)->assertUnprocessable()
            ->assertJsonPath('message', 'Confirme os componentes materiais da magia antes de conjurar.');
        $this->postJson($base.'/actions', [...$fire, 'spellCast' => ['slotLevel' => 2, 'centerTokenId' => 'orc',
            'componentsConfirmed' => true]])->assertUnprocessable();
        $foreign = ActorDocument::create(['actor_id' => $orc->id, 'catalog_entry_id' => $docs['fireball']->catalog_entry_id,
            'kind' => 'spell', 'name' => 'Fireball', 'slug' => 'fireball', 'source' => 'XPHB', 'data' => []]);
        $this->postJson($base.'/actions', [...$fire, 'actionId' => 'spell:'.$foreign->id,
            'spellCast' => ['slotLevel' => 3, 'centerTokenId' => 'orc', 'componentsConfirmed' => true]])->assertNotFound();
        $catalog = $docs['fireball']->catalogEntry;
        $data = $catalog->data;
        $data['spellLists'] = ['XPHB:Sorcerer'];
        $catalog->update(['data' => $data]);
        $this->postJson($base.'/actions', [...$fire, 'spellCast' => ['slotLevel' => 3, 'centerTokenId' => 'orc',
            'componentsConfirmed' => true]])->assertUnprocessable();
        $data['spellLists'] = ['XPHB:Wizard'];
        $catalog->update(['data' => $data]);
        $system = $caster->fresh()->system;
        $system['conditions'][] = 'silenced';
        $caster->update(['system' => $system]);
        $this->postJson($base.'/actions', [...$fire, 'spellCast' => ['slotLevel' => 3, 'centerTokenId' => 'orc',
            'componentsConfirmed' => true]])->assertUnprocessable()
            ->assertJsonPath('message', 'O conjurador não pode usar componentes verbais enquanto estiver silenciado.');
        $system['conditions'] = [];
        $system['spells']['slots']['3']['used'] = 2;
        $caster->update(['system' => $system]);
        $this->postJson($base.'/actions', [...$fire, 'spellCast' => ['slotLevel' => 3, 'centerTokenId' => 'orc',
            'componentsConfirmed' => true]])->assertUnprocessable();
        $this->assertDatabaseCount('roll_records', 0);
        $this->assertDatabaseCount('action_records', 0);
        $this->assertSame(2, $caster->fresh()->system['spells']['slots']['3']['used']);
    }

    public function test_hidden_target_allocation_and_area_center_are_redacted_from_player_history_after_reload(): void
    {
        [$gm, $player, $scene, $caster, , $orc, , , $docs, $base] = $this->encounter();
        $state = $scene->state;
        foreach ($state['tokens'] as &$token) {
            if ($token['id'] === 'orc') {
                $token['hidden'] = true;
            }
        }
        unset($token);
        $scene->update(['state' => $state]);
        Sanctum::actingAs($gm);
        $message = $this->postJson($base.'/actions', ['actorId' => $caster->id,
            'actionId' => 'spell:'.$docs['magic-missile']->id, 'targetTokenIds' => ['orc', 'goblin'],
            'spellCast' => ['slotLevel' => 1, 'missiles' => [['tokenId' => 'orc', 'count' => 2],
                ['tokenId' => 'goblin', 'count' => 1]]], 'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $this->assertSame($orc->id, $message['rolls'][0]['targetActorId']);
        $this->assertCount(2, $message['spellCast']['missiles']);
        $area = $this->postJson($base.'/actions', ['actorId' => $caster->id,
            'actionId' => 'spell:'.$docs['fireball']->id,
            'spellCast' => ['slotLevel' => 3, 'centerTokenId' => 'orc', 'componentsConfirmed' => true],
            'requestId' => (string) Str::uuid()])->assertOk()->json('message');
        $this->assertSame('orc', $area['spellCast']['areaCenterTokenId']);
        $reloaded = Scene::findOrFail($scene->id);
        $playerChat = $reloaded->stateFor($player)['chat'];
        $this->assertSame(['goblin'], array_column($playerChat[0]['spellCast']['missiles'], 'tokenId'));
        $this->assertNotContains('orc', $playerChat[0]['targetTokenIds']);
        $this->assertArrayNotHasKey('targetActorId', $playerChat[0]['rolls'][0]);
        $this->assertSame('goblin', $playerChat[0]['spellCast']['missiles'][0]['tokenId']);
        $this->assertArrayNotHasKey('areaCenterTokenId', $playerChat[1]['spellCast']);
        $stored = json_decode(DB::table('action_records')->where('message_id', $message['id'])->value('message'), true);
        $this->assertSame('orc', $stored['spellCast']['missiles'][0]['tokenId']);
    }

    public function test_2014_non_ritual_caster_cannot_use_the_ritual_flag_even_with_a_prepared_spell(): void
    {
        [, $player, , $caster, , , , , $docs, $base] = $this->encounter('5e-2014');
        $system = $caster->system;
        $system['progression']['classes'][0]['name'] = 'Sorcerer';
        $system['bio']['class'] = 'Sorcerer';
        $caster->update(['system' => $system]);
        $ritualDocument = $docs['detect-magic'];
        $ritualDocument->update(['prepared' => true]);
        $catalog = $ritualDocument->catalogEntry;
        $data = $catalog->data;
        $data['spellLists'] = ['PHB:Sorcerer'];
        $catalog->update(['data' => $data]);
        Sanctum::actingAs($player);
        $profiles = $this->getJson('/api/actors/'.$caster->id.'/spells')->assertOk()->json('spells');
        $this->assertFalse(collect($profiles)->firstWhere('documentId', $ritualDocument->id)['canRitual']);
        $this->postJson($base.'/actions', ['actorId' => $caster->id, 'actionId' => 'spell:'.$ritualDocument->id,
            'spellCast' => ['ritual' => true], 'requestId' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(0, $caster->fresh()->system['spells']['slots']['1']['used']);
    }
}
