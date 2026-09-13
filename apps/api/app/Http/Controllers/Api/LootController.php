<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\LootResult;
use App\Support\LootApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LootController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);

        return response()->json(['loot' => LootResult::where('campaign_id', $campaign->id)->latest()->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);
        $data = $this->validated($request, $campaign);
        $loot = LootResult::create(['campaign_id' => $campaign->id, 'status' => 'draft', ...$data]);

        return response()->json(['loot' => $loot], 201);
    }

    public function apply(Request $request, LootResult $lootResult): JsonResponse
    {
        $this->gm($request, $lootResult->campaign);
        $data = $request->validate(['actorId' => ['required', 'integer', Rule::exists('actors', 'id')->where('campaign_id', $lootResult->campaign_id)]]);
        $actor = DB::transaction(function () use ($lootResult, $data) {
            $loot = LootResult::whereKey($lootResult->id)->lockForUpdate()->firstOrFail();
            $actor = Actor::whereKey($data['actorId'])->lockForUpdate()->firstOrFail();

            return LootApplication::apply($loot, $actor);
        });

        return response()->json(['loot' => $lootResult->fresh(), 'actor' => $actor->toPayload()]);
    }

    private function validated(Request $request, Campaign $campaign): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'rollTableRollId' => ['nullable', 'integer', 'exists:roll_table_rolls,id'],
            'items' => ['nullable', 'array', 'max:100'], 'items.*.name' => ['required_with:items', 'string', 'max:160'],
            'items.*.slug' => ['nullable', 'string', 'max:160'], 'items.*.quantity' => ['nullable', 'integer', 'between:1,10000'],
            'items.*.description' => ['nullable', 'string', 'max:5000'],
            'currency' => ['nullable', 'array:cp,sp,ep,gp,pp'], 'currency.*' => ['integer', 'between:0,100000000'],
            'metadata' => ['nullable', 'array'],
        ]);
        if (isset($data['rollTableRollId'])) {
            $belongs = DB::table('roll_table_rolls')->join('roll_tables', 'roll_tables.id', '=', 'roll_table_rolls.roll_table_id')
                ->where('roll_table_rolls.id', $data['rollTableRollId'])->where('roll_tables.campaign_id', $campaign->id)->exists();
            abort_unless($belongs, 422, 'A rolagem precisa pertencer a esta campanha.');
            $data['roll_table_roll_id'] = $data['rollTableRollId'];
            unset($data['rollTableRollId']);
        }
        $data['items'] = $data['items'] ?? [];
        $data['currency'] = array_merge(['cp' => 0, 'sp' => 0, 'ep' => 0, 'gp' => 0, 'pp' => 0], $data['currency'] ?? []);
        $data['metadata'] = $data['metadata'] ?? [];

        return $data;
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->canManage($request->user()), 403, 'Apenas o GM pode gerenciar tesouro.');
    }
}
