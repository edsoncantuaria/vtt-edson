<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogEntry;
use App\Support\AdventureMaps;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CatalogMediaController extends Controller
{
    /** Only public upstream illustrations from stored catalog references are served. */
    public function show(CatalogEntry $entry, string $variant)
    {
        $isMap = preg_match('/^map-(\d+)$/', $variant, $matches) === 1;
        abort_unless(in_array($variant, ['token', 'art']) || ($isMap && $entry->kind === 'adventures'), 404);
        $url = $variant === 'token' ? ($entry->data['tokenUrl'] ?? null) : ($entry->data['images'][0]['url'] ?? null);
        if ($isMap) {
            $url = AdventureMaps::extract($entry->data['raw'] ?? [])[(int) $matches[1]]['url'] ?? null;
        }
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless($parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === '5e.tools'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && str_starts_with($parts['path'] ?? '', '/img/'), 404);
        $limit = ($isMap ? 15 : 5) * 1024 * 1024;
        $key = 'catalog-media/'.hash('sha256', $url);
        $disk = Storage::disk('local');
        if (! $disk->exists($key)) {
            try {
                $response = Http::timeout(15)->connectTimeout(5)->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
                abort_unless($response->successful(), 404);
                $stream = $response->toPsrResponse()->getBody();
                $bytes = '';
                while (! $stream->eof() && strlen($bytes) <= $limit) {
                    $bytes .= $stream->read(65536);
                }
                $stream->close();
                abort_if(strlen($bytes) > $limit, 413);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                if (! is_string($mime) || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    abort(415);
                }
                $disk->put($key, $bytes);
            } catch (ConnectionException $error) {
                abort(504, 'Imagem temporariamente indisponível.');
            }
        }
        $bytes = $disk->get($key);

        return response($bytes)->header('Content-Type', (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes))
            ->header('Cache-Control', 'public, max-age=86400')->header('X-Content-Type-Options', 'nosniff');
    }
}
