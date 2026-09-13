<?php

namespace Tests\Feature;

use App\Models\CompendiumSpell;
use App\Models\User;
use Database\Seeders\CompendiumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompendiumTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_spells_by_name(): void
    {
        CompendiumSpell::create(['slug' => 'fireball', 'name' => 'Bola de Fogo', 'level' => 3, 'school' => 'evocation', 'data' => []]);
        CompendiumSpell::create(['slug' => 'guidance', 'name' => 'Orientação', 'level' => 0, 'school' => 'divination', 'data' => []]);

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $res = $this->getJson('/api/compendium/spells?query=Fogo');

        $res->assertOk();
        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.slug', 'fireball');
    }

    public function test_unknown_compendium_kind_is_404(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson('/api/compendium/artifacts')->assertNotFound();
    }

    public function test_curated_seeder_populates_compendium(): void
    {
        (new CompendiumSeeder)->run();

        $this->assertDatabaseHas('compendium_monsters', ['slug' => 'goblin']);
        $this->assertDatabaseHas('compendium_items', ['slug' => 'longsword']);
        $this->assertDatabaseHas('compendium_spells', ['slug' => 'fireball']);
    }
}
