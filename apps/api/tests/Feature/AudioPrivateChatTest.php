<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AudioPrivateChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_playlists_are_campaign_scoped_and_gm_managed(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Music'])->assertCreated()->json();
        $campaignId = $room['campaign']['id'];

        $playlist = $this->postJson('/api/campaigns/'.$campaignId.'/playlists', ['name' => 'Dungeon'])
            ->assertCreated()->json('playlist');
        $this->postJson('/api/playlists/'.$playlist['id'].'/tracks', [
            'title' => 'Dripping Cavern', 'url' => 'https://example.test/cave.mp3', 'volume' => .4, 'loop' => true,
        ])->assertCreated()->assertJsonPath('track.loop', true);

        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson('/api/campaigns/'.$campaignId.'/playlists')->assertOk()
            ->assertJsonPath('playlists.0.name', 'Dungeon')
            ->assertJsonPath('playlists.0.tracks.0.title', 'Dripping Cavern');
        $this->postJson('/api/campaigns/'.$campaignId.'/playlists', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_private_messages_and_rolls_are_visible_only_to_participants(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Whispers'])->assertCreated()->json();
        $sceneId = $room['scene']['id'];
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();

        Sanctum::actingAs($gm);
        $this->postJson('/api/scenes/'.$sceneId.'/private-messages', [
            'audience' => 'user', 'recipientUserId' => $player->id, 'text' => 'A porta fala com você.',
        ])->assertCreated()->assertJsonPath('message.kind', 'text');

        Sanctum::actingAs($player);
        $this->postJson('/api/scenes/'.$sceneId.'/private-messages', [
            'audience' => 'gm', 'text' => '/roll 1d20+3',
        ])->assertCreated()->assertJsonPath('message.kind', 'roll');
        $this->getJson('/api/scenes/'.$sceneId.'/private-messages')->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonFragment(['name' => $gm->name]);

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/scenes/'.$sceneId.'/private-messages')->assertForbidden();
    }
}
