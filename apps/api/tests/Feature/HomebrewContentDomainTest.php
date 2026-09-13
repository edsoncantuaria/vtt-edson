<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\Campaign;
use App\Models\HomebrewEntry;
use App\Models\HomebrewPackage;
use App\Models\LootResult;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use App\Support\LootApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HomebrewContentDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_homebrew_package_keeps_version_enabled_state_and_structured_entries(): void
    {
        $gm = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Homebrew']);
        $package = HomebrewPackage::create([
            'campaign_id' => $campaign->id, 'name' => 'Arcana local', 'version' => '2.1.0',
            'enabled' => false, 'metadata' => ['author' => 'Mesa'],
        ]);
        HomebrewEntry::create([
            'homebrew_package_id' => $package->id, 'kind' => 'feat', 'name' => 'Passo Sombrio',
            'slug' => 'passo-sombrio', 'version' => '2.1.0', 'data' => ['uses' => 1, 'reset' => 'long'],
        ]);

        $loaded = $package->fresh()->load('entries');
        $this->assertFalse($loaded->enabled);
        $this->assertSame('2.1.0', $loaded->version);
        $this->assertSame(1, $loaded->entries[0]->data['uses']);
    }

    public function test_loot_is_applied_once_to_inventory_and_currency(): void
    {
        $gm = User::factory()->create();
        $campaign = Campaign::create(['owner_id' => $gm->id, 'name' => 'Treasure']);
        $actor = Actor::create([
            'campaign_id' => $campaign->id, 'owner_user_id' => $gm->id, 'type' => 'character',
            'name' => 'Hero', 'system' => ActorStateFactory::character(),
        ]);
        $loot = LootResult::create([
            'campaign_id' => $campaign->id, 'name' => 'Baú', 'status' => 'draft',
            'items' => [['name' => 'Potion', 'slug' => 'potion', 'quantity' => 2]],
            'currency' => ['gp' => 25, 'cp' => 0, 'sp' => 0, 'ep' => 0, 'pp' => 0],
        ]);

        $updated = LootApplication::apply($loot, $actor);
        $this->assertSame(2, $updated->system['inventory'][0]['quantity']);
        $this->assertSame(25, $updated->system['currency']['gp']);
        $this->assertSame('applied', $loot->fresh()->status);
        $this->expectException(HttpException::class);
        LootApplication::apply($loot->fresh(), $updated);
    }
}
