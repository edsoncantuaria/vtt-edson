<?php

namespace App\Support;

use App\Game\Dice\DiceRoller;
use App\Models\RollTable;

final class RollTableExecutor
{
    public function __construct(private DiceRoller $dice) {}

    public function execute(RollTable $table): array
    {
        abort_unless($table->enabled, 422, 'Esta tabela está desativada.');
        $roll = $this->dice->roll($table->formula);
        $entry = collect($table->entries)->first(fn ($entry) => $roll['total'] >= (int) $entry['min'] && $roll['total'] <= (int) $entry['max']);
        abort_unless($entry !== null, 422, 'A rolagem não corresponde a nenhuma faixa da tabela.');

        return ['roll' => $roll, 'entry' => $entry];
    }
}
