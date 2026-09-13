<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompendiumItem;
use App\Models\CompendiumMonster;
use App\Models\CompendiumSpell;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompendiumController extends Controller
{
    private const MODELS = [
        'spells' => CompendiumSpell::class,
        'items' => CompendiumItem::class,
        'monsters' => CompendiumMonster::class,
    ];

    public function index(Request $request, string $kind): JsonResponse
    {
        $model = self::MODELS[$kind] ?? null;
        if ($model === null) {
            abort(404, 'Compêndio desconhecido.');
        }

        /** @var Builder $query */
        $query = $model::query();

        if ($search = $request->query('query')) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        if ($kind === 'spells' && $request->filled('level')) {
            $query->where('level', (int) $request->query('level'));
        }
        if ($kind === 'items' && $request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $entries = $query->orderBy('name')->paginate(min((int) $request->query('perPage', 30), 100));

        return response()->json($entries);
    }

    public function show(Request $request, string $kind, string $slug): JsonResponse
    {
        $model = self::MODELS[$kind] ?? null;
        if ($model === null) {
            abort(404, 'Compêndio desconhecido.');
        }

        $entry = $model::query()->where('slug', $slug)->firstOrFail();

        return response()->json(['entry' => $entry]);
    }
}
