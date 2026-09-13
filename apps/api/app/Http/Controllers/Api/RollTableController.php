<?php

namespace App\Http\Controllers\Api;

use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\RollTable;
use App\Models\RollTableRoll;
use App\Support\FiveToolsIntegrationService;
use App\Support\RollTableExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RollTableController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        $query = RollTable::where('campaign_id', $campaign->id)->orderBy('name');
        if (! $campaign->canManage($request->user())) {
            $query->where('enabled', true);
        }

        return response()->json(['tables' => $query->get()]);
    }

    public function store(Request $request, Campaign $campaign, DiceRoller $dice): JsonResponse
    {
        $this->gm($request, $campaign);
        $table = RollTable::create(['campaign_id' => $campaign->id, ...$this->validated($request, $dice, false)]);

        return response()->json(['table' => $table], 201);
    }

    public function update(Request $request, RollTable $rollTable, DiceRoller $dice): JsonResponse
    {
        $this->gm($request, $rollTable->campaign);
        $rollTable->update($this->validated($request, $dice, true));

        return response()->json(['table' => $rollTable->fresh()]);
    }

    public function roll(Request $request, RollTable $rollTable, RollTableExecutor $executor, FiveToolsIntegrationService $integration): JsonResponse
    {
        abort_unless($rollTable->campaign->isMember($request->user()), 403);
        abort_if($rollTable->campaign->roleFor($request->user()) === 'observer', 403, 'Observadores possuem acesso somente de leitura.');
        if (data_get($rollTable->metadata, 'fiveTools.gmOnly')) {
            abort_unless($rollTable->campaign->canManage($request->user()), 403, 'Esta tabela integrada é uma ferramenta do mestre.');
        }
        $result = $executor->execute($rollTable);
        $record = RollTableRoll::create([
            'roll_table_id' => $rollTable->id, 'user_id' => $request->user()->id,
            'total' => $result['roll']['total'], 'result' => $result,
        ]);
        $materialized = $integration->materializeRoll($rollTable, $record, $result['entry']);

        return response()->json(['record' => $record, ...$result, ...$materialized]);
    }

    public function destroy(Request $request, RollTable $rollTable): JsonResponse
    {
        $this->gm($request, $rollTable->campaign);
        $rollTable->delete();

        return response()->json([], 204);
    }

    private function validated(Request $request, DiceRoller $dice, bool $update): array
    {
        $data = $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:160'],
            'formula' => [$update ? 'sometimes' : 'required', 'string', 'max:40'], 'enabled' => ['sometimes', 'boolean'],
            'entries' => [$update ? 'sometimes' : 'required', 'array', 'min:1', 'max:500'],
            'entries.*.min' => ['required', 'integer', 'between:-100000,100000'], 'entries.*.max' => ['required', 'integer', 'between:-100000,100000'],
            'entries.*.label' => ['required', 'string', 'max:500'], 'entries.*.result' => ['nullable', 'array'], 'metadata' => ['nullable', 'array'],
        ]);
        if (isset($data['formula'])) {
            abort_unless($dice->isValid($data['formula']), 422, 'Fórmula da tabela inválida.');
        }
        foreach ($data['entries'] ?? [] as $entry) {
            abort_unless($entry['min'] <= $entry['max'], 422, 'Cada faixa precisa começar antes de terminar.');
        }

        return $data;
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        abort_unless($campaign->canManage($request->user()), 403, 'Apenas o GM pode editar tabelas.');
    }
}
