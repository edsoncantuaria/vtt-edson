<?php

namespace Tests\Feature;

use App\Models\CatalogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogMediaTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $url): CatalogEntry
    {
        return CatalogEntry::create(['slug' => hash('sha256', $url), 'kind' => 'monsters', 'name' => 'Creature', 'source' => 'MM', 'edition' => '5e-2014', 'data' => ['tokenUrl' => $url]]);
    }

    public function test_public_token_is_cached_and_served_as_an_image(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        Http::fake(['https://5e.tools/img/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $entry = $this->entry('https://5e.tools/img/bestiary/tokens/MM/Aboleth.webp');
        $url = '/api/catalog-media/'.$entry->id.'/token';
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($url)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_other_hosts_and_non_images_are_rejected(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response('<script>bad</script>', 200)]);
        $entry = $this->entry('https://127.0.0.1/img/private');
        $this->get('/api/catalog-media/'.$entry->id.'/token')->assertNotFound();
        Http::assertNothingSent();
        $entry = $this->entry('https://5e.tools/img/bad.webp');
        $this->get('/api/catalog-media/'.$entry->id.'/token')->assertStatus(415);
    }
}
