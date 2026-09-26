<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Scene;
use App\Models\User;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SceneStateUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function legacyScene(): Scene
    {
        $gm = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Legacy']);
        $state = SceneStateFactory::empty();
        unset($state['schemaVersion']);
        $state['tokens'][] = ['id' => 'old', 'name' => 'Hero', 'x' => 3, 'y' => 5, 'ownerUserId' => $gm->id];

        return Scene::create(['campaign_id' => $campaign->id, 'name' => 'Old', 'state' => $state]);
    }

    public function test_new_scenes_are_versioned_and_legacy_upgrade_is_dry_run_by_default_and_reversible(): void
    {
        $this->assertSame(2, SceneStateFactory::empty()['schemaVersion']);
        $scene = $this->legacyScene();
        $before = $scene->state;
        $this->assertSame(0, Artisan::call('scene-state:upgrade', ['--scene' => $scene->id]));
        $this->assertSame($before, $scene->fresh()->state);
        $this->assertDatabaseCount('scene_state_upgrade_backups', 0);

        $this->assertSame(0, Artisan::call('scene-state:upgrade', ['--apply' => true, '--scene' => $scene->id]));
        $this->assertSame(2, $scene->fresh()->state['schemaVersion']);
        $this->assertDatabaseHas('scene_state_upgrade_backups', ['scene_id' => $scene->id]);
        // Applying twice is safe: the original snapshot is never replaced.
        $this->assertSame(0, Artisan::call('scene-state:upgrade', ['--apply' => true, '--scene' => $scene->id]));
        $this->assertDatabaseCount('scene_state_upgrade_backups', 1);
        $this->assertSame(0, Artisan::call('scene-state:upgrade', ['--restore' => true, '--scene' => $scene->id]));
        $this->assertSame($before, $scene->fresh()->state);
        $this->assertDatabaseCount('scene_state_upgrade_backups', 0);
    }

    public function test_restore_refuses_to_overwrite_subsequent_gameplay_and_conflicting_token_snapshots(): void
    {
        $scene = $this->legacyScene();
        $this->assertSame(0, Artisan::call('scene-state:upgrade', ['--apply' => true, '--scene' => $scene->id]));
        $state = $scene->fresh()->state;
        $state['tokens'][0]['x'] = 99;
        $scene->update(['state' => $state]);
        $this->assertSame(1, Artisan::call('scene-state:upgrade', ['--restore' => true, '--scene' => $scene->id]));
        $this->assertSame(99, $scene->fresh()->state['tokens'][0]['x']);
        $this->assertSame(1, DB::table('scene_state_upgrade_backups')->count());

        $other = $this->legacyScene();
        $state = $other->state;
        $state['tokens'][0]['combat'] = ['hp' => ['value' => 1, 'max' => 2]];
        $other->update(['state' => $state]);
        $this->assertSame(1, Artisan::call('scene-state:upgrade', ['--apply' => true, '--scene' => $other->id]));
        $this->assertArrayNotHasKey('schemaVersion', $other->fresh()->state);
        $this->assertDatabaseMissing('scene_state_upgrade_backups', ['scene_id' => $other->id]);
    }
}
