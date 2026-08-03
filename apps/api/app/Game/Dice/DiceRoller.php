<?php

namespace App\Game\Dice;

use InvalidArgumentException;

final class DiceRoller
{
    private const PATTERN = '/^\s*(\d*)d(\d+)([+-]\d+)?\s*$/i';

    /**
     * @return array{formula: string, total: int, detail: string, rolls: list<int>}
     */
    public function roll(string $formula): array
    {
        if (! preg_match(self::PATTERN, $formula, $m)) {
            throw new InvalidArgumentException('Fórmula de dados inválida. Use NdM±K (ex: 2d6+3).');
        }

        $count = $m[1] !== '' ? (int) $m[1] : 1;
        $sides = (int) $m[2];
        $modifier = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;

        if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
            throw new InvalidArgumentException('Parâmetros de dados fora do intervalo permitido.');
        }

        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $sides);
        }

        $sum = array_sum($rolls) + $modifier;
        $detail = implode(' + ', $rolls);
        if ($modifier !== 0) {
            $detail .= ($modifier > 0 ? ' + ' : ' - ').abs($modifier);
        }
        $detail .= ' = '.$sum;

        $normalized = ($count === 1 ? 'd' : $count.'d').$sides;
        if ($modifier > 0) {
            $normalized .= '+'.$modifier;
        } elseif ($modifier < 0) {
            $normalized .= (string) $modifier;
        }

        return [
            'formula' => $normalized,
            'total' => $sum,
            'detail' => $detail,
            'rolls' => $rolls,
        ];
    }

    public function isValid(string $formula): bool
    {
        try {
            $this->roll($formula);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
