<?php

namespace Tests\Feature;

use App\Http\Middleware\SerializeSceneWrites;
use App\Models\Campaign;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\SceneStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

class SceneWriteTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_route_binding_is_reloaded_and_failed_write_rolls_back(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $user->id, 'name' => 'Table']);
        $scene = Scene::create(['campaign_id' => $campaign->id, 'name' => 'Map', 'state' => SceneStateFactory::empty()]);
        SceneMember::create(['scene_id' => $scene->id, 'user_id' => $user->id, 'role' => 'gm']);
        $request = Request::create('/scenes/'.$scene->id.'/grid', 'PATCH');
        $request->setUserResolver(fn () => $user);
        $route = new Route('PATCH', 'scenes/{scene}/grid', fn () => null);
        $route->bind($request);
        $route->setParameter('scene', $scene);
        $request->setRouteResolver(fn () => $route);
        $fresh = Scene::find($scene->id);
        $state = $fresh->state;
        $state['grid']['size'] = 90;
        $fresh->update(['state' => $state]);
        try {
            (new SerializeSceneWrites)->handle($request, function ($request) {
                $current = $request->route('scene');
                $this->assertSame(90, $current->state['grid']['size']);
                $current->update(['name' => 'Failed change']);
                throw new \RuntimeException('rollback');
            });
            $this->fail('Expected rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }
        $this->assertSame('Map', $scene->fresh()->name);
        $this->assertSame(90, $scene->fresh()->state['grid']['size']);
    }
}
