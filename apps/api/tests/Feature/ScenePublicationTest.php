<?php

namespace Tests\Feature;

use App\Events\SceneUpdated;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScenePublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparation_is_private_until_published_and_can_be_revoked(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Campaign'])->json();
        $url = '/api/campaigns/'.$room['campaign']['id'].'/scenes';
        $scene = $this->postJson($url, ['name' => 'Secret chamber'])->assertCreated()->json('scene');
        $this->assertFalse($scene['published']);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        $this->getJson($url)->assertJsonCount(1, 'scenes');
        $this->getJson('/api/scenes/'.$scene['id'])->assertForbidden();
        $this->getJson('/api/scenes/'.$scene['id'].'/combat')->assertForbidden();
        Sanctum::actingAs($gm);
        $this->patchJson('/api/scenes/'.$scene['id'], ['published' => true])->assertOk();
        Sanctum::actingAs($player);
        $this->getJson($url)->assertJsonCount(2, 'scenes');
        $this->getJson('/api/scenes/'.$scene['id'])->assertOk();
        Sanctum::actingAs($gm);
        $this->patchJson('/api/scenes/'.$scene['id'], ['published' => false])->assertOk();
        $this->patchJson('/api/scenes/'.$room['scene']['id'], ['published' => false])->assertUnprocessable();
        Sanctum::actingAs($player);
        $this->getJson('/api/scenes/'.$scene['id'])->assertForbidden();
        $payload = (new SceneUpdated(Scene::find($scene['id']), 'token'))->broadcastWith();
        $this->assertArrayNotHasKey('state', $payload);
        $this->assertArrayNotHasKey('name', $payload);
    }
}
