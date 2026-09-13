<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\SceneMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_requires_authentication(): void
    {
        $this->getJson('/api/rooms')->assertUnauthorized();
    }

    public function test_rooms_are_scoped_to_the_account_and_keep_roles_after_rejoining(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $outsider = User::factory()->create();
        Sanctum::actingAs($gm);
        $created = $this->postJson('/api/rooms', ['name' => 'Valdora', 'ruleset' => '5e-2024'])
            ->assertCreated()->assertJsonPath('scene.role', 'gm')->assertJsonPath('campaign.ruleset', '5e-2024')->json();
        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(1, 'rooms')
            ->assertJsonPath('rooms.0.name', 'Valdora')->assertJsonPath('rooms.0.role', 'gm')
            ->assertJsonPath('rooms.0.ruleset', '5e-2024')->assertJsonPath('rooms.0.memberCount', 1);
        $this->postJson('/api/rooms/join', ['code' => $created['room']['code']])
            ->assertOk()->assertJsonPath('scene.role', 'gm');

        Sanctum::actingAs($player);
        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(0, 'rooms');
        $this->postJson('/api/rooms/join', ['code' => strtolower($created['room']['code'])])
            ->assertOk()->assertJsonPath('scene.role', 'player');
        $this->postJson('/api/rooms/join', ['code' => $created['room']['code']])->assertOk();
        $this->assertSame(2, SceneMember::where('scene_id', $created['scene']['id'])->count());
        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(1, 'rooms')
            ->assertJsonPath('rooms.0.role', 'player')->assertJsonPath('rooms.0.memberCount', 2)
            ->assertJsonPath('rooms.0.sceneId', $created['scene']['id']);

        Sanctum::actingAs($outsider);
        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(0, 'rooms');
        $this->getJson('/api/scenes/'.$created['scene']['id'])->assertForbidden();
    }

    public function test_default_edition_preserves_legacy_behavior_and_unknown_editions_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/rooms', ['name' => 'Legado'])->assertCreated()
            ->assertJsonPath('campaign.ruleset', '5e-2014');
        $this->postJson('/api/rooms', ['name' => 'Inválida', 'ruleset' => 'other'])->assertUnprocessable();
        $this->assertSame(1, Campaign::count());
        $this->postJson('/api/rooms/join', ['code' => 'BADCODE'])->assertNotFound();
    }

    public function test_removed_membership_removes_room_from_the_library(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Privada'])->assertCreated()->json();
        $player = User::factory()->create();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();
        SceneMember::where('scene_id', $room['scene']['id'])->where('user_id', $player->id)->delete();
        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(0, 'rooms');
    }
}
