<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalHandoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_selective_handout_is_visible_only_to_selected_campaign_players(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Handouts'])->assertCreated()->json();
        $first = User::factory()->create(['name' => 'Alice']);
        $second = User::factory()->create(['name' => 'Bob']);

        foreach ([$first, $second] as $player) {
            Sanctum::actingAs($player);
            $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        }

        Sanctum::actingAs($gm);
        $url = '/api/campaigns/'.$room['campaign']['id'].'/journals';
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'shareTargets');
        $note = $this->postJson($url, [
            'title' => 'Carta secreta',
            'body' => 'Para Alice',
            'visibility' => 'selected',
            'scene_id' => $room['scene']['id'],
            'folder' => 'Handouts/Cartas',
            'shared_user_ids' => [$first->id],
            'attachments' => [['type' => 'url', 'label' => 'Fac-símile', 'url' => 'https://example.com/letter.webp']],
            'metadata' => ['kind' => 'handout'],
        ])->assertCreated()->json('entry');
        $this->assertSame([$first->id], $note['shared_user_ids']);

        Sanctum::actingAs($first);
        $this->getJson($url)->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.title', 'Carta secreta')->assertJsonMissingPath('shareTargets.0');
        Sanctum::actingAs($second);
        $this->getJson($url)->assertJsonCount(0, 'entries');
    }

    public function test_handout_rejects_unsafe_urls_and_players_outside_campaign(): void
    {
        $gm = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Handouts'])->assertCreated()->json();
        $outsider = User::factory()->create();
        $url = '/api/campaigns/'.$room['campaign']['id'].'/journals';
        $base = ['title' => 'Nota', 'body' => '', 'visibility' => 'gm'];

        $this->postJson($url, [...$base, 'attachments' => [['type' => 'url', 'url' => 'http://example.com/file.png']]])->assertUnprocessable();
        $this->postJson($url, [...$base, 'attachments' => [['type' => 'url', 'url' => 'https://user@example.com/file.png']]])->assertUnprocessable();
        $this->postJson($url, [...$base, 'visibility' => 'selected', 'shared_user_ids' => [$outsider->id]])->assertUnprocessable();
    }
}
