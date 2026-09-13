<?php

namespace App\Http\Middleware;

use App\Models\Scene;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Scene JSON and its combat share one write lock across connected clients. */
class SerializeSceneWrites
{
    public function handle(Request $request, Closure $next)
    {
        $scene = $request->route('scene');
        if ($request->isMethodSafe() || ! $scene instanceof Scene) {
            return $next($request);
        }
        abort_unless($scene->memberFor($request->user()) !== null, 403);

        return DB::transaction(function () use ($request, $next, $scene) {
            // Route binding may predate a competing write; reload under the lock.
            $current = Scene::whereKey($scene->id)->lockForUpdate()->firstOrFail();
            $request->route()->setParameter('scene', $current);

            return $next($request);
        });
    }
}
