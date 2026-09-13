<?php

namespace Tests\Feature;

use App\Models\CatalogEntry;
use App\Models\HomebrewEntry;
use App\Models\HomebrewPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogImportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function ndjson(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-');
        foreach ($rows as $row) {
            file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        }
        $this->files[] = $path;

        return $path;
    }

    private function row(string $identity, string $name): array
    {
        return [
            'slug' => hash('sha256', $identity), 'kind' => 'monsters', 'name' => $name,
            'source' => 'MM', 'edition' => '5e-2014', 'level' => null,
            'data' => ['format' => '5etools', 'raw' => ['cr' => '1']],
        ];
    }

    public function test_import_updates_in_place_reactivates_current_and_deactivates_only_missing_fivetools(): void
    {
        $one = $this->row('one', 'Goblin');
        $two = $this->row('two', 'Orc');
        $this->artisan('compendium:import', ['file' => $this->ndjson([$one, $two])])->assertSuccessful();
        $id = CatalogEntry::where('slug', $one['slug'])->value('id');
        $this->assertTrue(CatalogEntry::where('slug', $two['slug'])->firstOrFail()->active);

        $one['name'] = 'Goblin Veteran';
        $this->artisan('compendium:import', ['file' => $this->ndjson([$one]), '--deactivate-missing' => true])->assertSuccessful();
        $updated = CatalogEntry::where('slug', $one['slug'])->firstOrFail();
        $this->assertSame($id, $updated->id);
        $this->assertSame('Goblin Veteran', $updated->name);
        $this->assertTrue($updated->active);
        $this->assertFalse(CatalogEntry::where('slug', $two['slug'])->firstOrFail()->active);
        $this->assertIsArray($updated->data);
        $this->assertDatabaseCount('catalog_imports', 2);
    }

    public function test_duplicate_or_invalid_input_fails_before_mutating_catalog(): void
    {
        $row = $this->row('same', 'One');
        $this->artisan('compendium:import', ['file' => $this->ndjson([$row, [...$row, 'name' => 'Two']])])->assertFailed();
        $this->assertDatabaseCount('catalog_entries', 0);
        $this->assertDatabaseCount('catalog_imports', 0);
    }

    public function test_catalog_hides_inactive_entries_and_exposes_enabled_homebrew_without_id_collision(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = $this->postJson('/api/rooms', ['name' => 'Library', 'library' => 'all'])->assertCreated()->json();
        CatalogEntry::create(['slug' => hash('sha256', 'inactive'), 'kind' => 'monsters', 'name' => 'Old', 'source' => 'MM', 'edition' => '5e-2014', 'data' => [], 'active' => false]);
        CatalogEntry::create(['slug' => hash('sha256', 'active'), 'kind' => 'monsters', 'name' => 'Official', 'source' => 'MM', 'edition' => '5e-2014', 'data' => [], 'active' => true]);
        $enabled = HomebrewPackage::create(['campaign_id' => $room['campaign']['id'], 'name' => 'Enabled', 'version' => '1', 'enabled' => true]);
        $disabled = HomebrewPackage::create(['campaign_id' => $room['campaign']['id'], 'name' => 'Disabled', 'version' => '1', 'enabled' => false]);
        $homebrew = HomebrewEntry::create(['homebrew_package_id' => $enabled->id, 'kind' => 'monsters', 'name' => 'Custom', 'slug' => 'custom', 'version' => '1', 'data' => []]);
        HomebrewEntry::create(['homebrew_package_id' => $disabled->id, 'kind' => 'monsters', 'name' => 'Hidden custom', 'slug' => 'hidden', 'version' => '1', 'data' => []]);

        $response = $this->getJson('/api/catalog/monsters?campaignId='.$room['campaign']['id'])->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Official')->assertJsonCount(1, 'homebrew')->assertJsonPath('homebrew.0.name', 'Custom');
        $this->assertSame($homebrew->id, $response->json('homebrew.0.homebrewId'));
        $this->assertArrayNotHasKey('id', $response->json('homebrew.0'));

        $this->getJson('/api/catalog/monsters?campaignId='.$room['campaign']['id'].'&includeInactive=1')->assertOk()->assertJsonCount(2, 'data');
    }
}
