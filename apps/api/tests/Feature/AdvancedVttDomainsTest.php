<?php

namespace Tests\Feature;

use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CampaignMember;
use App\Models\CampaignSubsystem;
use App\Models\CatalogEntry;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvancedVttDomainsTest extends TestCase
{
    use RefreshDatabase;

    private function room(): array
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Advanced', 'ruleset' => '5e-2024'])
            ->assertCreated()->json();

        return [$room, $gm];
    }

    public function test_documents_effects_charges_rest_and_action_undo_are_integrated(): void
    {
        [$room] = $this->room();
        $campaignId = $room['campaign']['id'];
        $sceneId = $room['scene']['id'];
        $item = CatalogEntry::create([
            'slug' => hash('sha256', 'wand'), 'kind' => 'items', 'name' => 'Wand', 'source' => 'DMG',
            'edition' => '5e-2024', 'data' => ['format' => '5etools', 'description' => 'Wand'],
        ]);
        $sourceSystem = ActorStateFactory::character();
        $sourceSystem['actions'] = [[
            'id' => 'ward', 'name' => 'Ward', 'kind' => 'spell', 'attackFormula' => '1d20+4',
            'effect' => [
                'name' => 'Ward AC', 'target' => 'targets',
                'duration' => ['unit' => 'rounds', 'remaining' => 2],
                'modifiers' => [['path' => 'ac', 'mode' => 'add', 'value' => 2]],
                'conditions' => ['warded'],
            ],
        ]];
        $source = $this->postJson('/api/campaigns/'.$campaignId.'/actors', [
            'name' => 'Caster', 'type' => 'character', 'system' => $sourceSystem,
        ])->assertCreated()->json('actor');
        $target = $this->postJson('/api/campaigns/'.$campaignId.'/actors', [
            'name' => 'Target', 'type' => 'character', 'system' => ActorStateFactory::character(),
        ])->assertCreated()->json('actor');

        $document = $this->postJson('/api/actors/'.$source['id'].'/documents', [
            'catalogEntryId' => $item->id, 'kind' => 'item',
        ])->assertCreated()->json('document');
        $this->patchJson('/api/actor-documents/'.$document['id'], [
            'attuned' => true, 'charges' => ['value' => 2, 'max' => 2, 'reset' => 'long'],
        ])->assertOk()->assertJsonPath('document.attuned', true)->assertJsonPath('document.charges.value', 2);

        $actor = Actor::findOrFail($source['id']);
        $system = $actor->system;
        $system['actions'][0]['documentId'] = $document['id'];
        $system['actions'][0]['chargeCost'] = 1;
        $this->patchJson('/api/actors/'.$actor->id, ['system' => $system, 'revision' => $actor->revision])
            ->assertOk();

        $message = $this->postJson('/api/scenes/'.$sceneId.'/actions', [
            'actorId' => $source['id'], 'actionId' => 'ward', 'requestId' => (string) Str::uuid(),
            'targetActorIds' => [$target['id']],
        ])->assertOk()->json('message');
        $this->assertSame(1, ActorDocument::findOrFail($document['id'])->charges['value']);
        $effect = ActiveEffect::where('actor_id', $target['id'])->firstOrFail();
        $this->assertSame('Ward AC', $effect->name);
        $this->assertSame(2, $effect->duration['remaining']);

        $this->postJson('/api/scenes/'.$sceneId.'/actions/'.$message['id'].'/undo', ['actorId' => $source['id']])
            ->assertOk();
        $this->assertSame(2, ActorDocument::findOrFail($document['id'])->charges['value']);
        $this->assertDatabaseMissing('active_effects', ['id' => $effect->id]);

        ActiveEffect::create([
            'actor_id' => $source['id'], 'name' => 'Until rest',
            'duration' => ['unit' => 'until-long-rest'], 'modifiers' => [], 'conditions' => [], 'metadata' => [], 'active' => true,
        ]);
        ActorDocument::findOrFail($document['id'])->update(['charges' => ['value' => 0, 'max' => 2, 'reset' => 'long']]);
        $this->postJson('/api/actors/'.$source['id'].'/rest', ['rest' => 'long'])
            ->assertOk()->assertJsonPath('actor.system.hp.value', 10);
        $this->assertSame(2, ActorDocument::findOrFail($document['id'])->charges['value']);
        $this->assertFalse(ActiveEffect::where('actor_id', $source['id'])->firstOrFail()->active);
    }

    public function test_assistant_permissions_canvas_and_assets_work_without_promoting_player_to_owner(): void
    {
        Storage::fake('public');
        [$room, $gm] = $this->room();
        $campaignId = $room['campaign']['id'];
        $sceneId = $room['scene']['id'];
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $membership = CampaignMember::where(['campaign_id' => $campaignId, 'user_id' => $player->id])->firstOrFail();

        Sanctum::actingAs($gm);
        $this->patchJson('/api/campaigns/'.$campaignId.'/members/'.$membership->id, ['role' => 'assistant'])
            ->assertOk()->assertJsonPath('member.role', 'assistant');
        Sanctum::actingAs($player);
        $this->postJson('/api/scenes/'.$sceneId.'/canvas/drawings', [
            'points' => [['x' => 10, 'y' => 20], ['x' => 30, 'y' => 40]],
        ])->assertOk()->assertJsonCount(1, 'state.drawings');
        $this->postJson('/api/scenes/'.$sceneId.'/canvas/regions', [
            'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'name' => 'Danger', 'behavior' => 'danger',
        ])->assertOk()->assertJsonCount(1, 'state.regions');
        $this->postJson('/api/scenes/'.$sceneId.'/canvas/pings', ['x' => 50, 'y' => 50])
            ->assertOk()->assertJsonCount(1, 'state.pings');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $asset = $this->post('/api/campaigns/'.$campaignId.'/assets', [
            'file' => UploadedFile::fake()->createWithContent('prop.png', $png),
        ])->assertCreated()->json('asset');
        $this->post('/api/campaigns/'.$campaignId.'/assets', [
            'file' => UploadedFile::fake()->createWithContent('prop-copy.png', $png),
        ])->assertOk()->assertJsonPath('deduplicated', true);
        $this->postJson('/api/scenes/'.$sceneId.'/canvas/tiles', [
            'assetId' => $asset['id'], 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 80,
        ])->assertOk()->assertJsonCount(1, 'state.tiles');
    }

    public function test_macros_modules_and_optional_subsystems_are_safe_and_stateful(): void
    {
        [$room] = $this->room();
        $campaignId = $room['campaign']['id'];
        $sceneId = $room['scene']['id'];
        $actor = $this->postJson('/api/campaigns/'.$campaignId.'/actors', [
            'name' => 'Hero', 'type' => 'character', 'system' => ActorStateFactory::character(),
        ])->assertCreated()->json('actor');

        $macro = $this->postJson('/api/campaigns/'.$campaignId.'/macros', [
            'name' => 'Bless self', 'visibility' => 'campaign', 'hotbar_slot' => 1,
            'commands' => [
                ['type' => 'roll', 'formula' => '1d20+2', 'label' => 'Check'],
                ['type' => 'effect', 'name' => 'Bless', 'target' => 'self',
                    'duration' => ['unit' => 'rounds', 'remaining' => 1],
                    'modifiers' => [['path' => 'roll.attack', 'mode' => 'add', 'value' => 1]], 'conditions' => []],
            ],
        ])->assertCreated()->json('macro');
        $this->postJson('/api/campaign-macros/'.$macro['id'].'/execute', ['sceneId' => $sceneId, 'actorId' => $actor['id']])
            ->assertOk()->assertJsonCount(1, 'messages');
        $this->assertDatabaseHas('active_effects', ['actor_id' => $actor['id'], 'name' => 'Bless']);

        $this->postJson('/api/campaigns/'.$campaignId.'/modules', [
            'moduleId' => 'bad.remote', 'name' => 'Bad', 'version' => '1.0.0',
            'manifest' => ['apiVersion' => 1, 'script' => 'https://evil.test/plugin.js'],
        ])->assertUnprocessable();
        $this->postJson('/api/campaigns/'.$campaignId.'/modules', [
            'moduleId' => 'safe.module', 'name' => 'Safe', 'version' => '1.0.0',
            'manifest' => ['apiVersion' => 1, 'id' => 'safe.module'],
        ])->assertCreated();

        $deck = CatalogEntry::create([
            'slug' => hash('sha256', 'deck'), 'kind' => 'decks', 'name' => 'Deck', 'source' => 'DMG',
            'edition' => '5e-2024', 'data' => ['format' => '5etools', 'raw' => ['cards' => ['A', 'B', 'C']]],
        ]);
        $subsystem = $this->postJson('/api/campaigns/'.$campaignId.'/subsystems', ['catalogEntryId' => $deck->id])
            ->assertCreated()->json('subsystem');
        $this->postJson('/api/campaign-subsystems/'.$subsystem['id'].'/actions', ['action' => 'deck.draw'])
            ->assertOk()->assertJsonPath('subsystem.state.draw.0', fn ($value) => is_string($value));
        $this->assertCount(2, CampaignSubsystem::findOrFail($subsystem['id'])->state['draw']);
    }
}
