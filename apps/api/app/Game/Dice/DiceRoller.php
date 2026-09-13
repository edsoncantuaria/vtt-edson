<?php

namespace App\Game\Dice;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class DiceRoller
{
    // NdM, com kh/kl opcional (vantagem/desvantagem: 2d20kh1 / 2d20kl1) e modificador +-K.
    private const PATTERN = '/^\s*(\d*)d(\d+)(?:(kh|kl)(\d*))?([+-]\d+)?\s*$/i';

    /**
     * @return array{formula: string, total: int, detail: string, rolls: list<int>, kept: list<int>, natural: int|null, critical: bool, fumble: bool}
     */
    public function roll(string $formula): array
    {
        if (! preg_match(self::PATTERN, trim($formula), $m)) {
            throw new InvalidArgumentException('Fórmula de dados inválida. Use NdM±K (ex: 2d6+3) ou NdMkh1/kl1 para vantagem/desvantagem.');
        }

        $count = $m[1] !== '' ? (int) $m[1] : 1;
        $sides = (int) $m[2];
        $keepMode = ($m[3] ?? '') !== '' ? Str::lower($m[3]) : null;
        $keepCount = $keepMode !== null
            ? (($m[4] ?? '') !== '' ? (int) $m[4] : 1)
            : null;
        $modifier = ($m[5] ?? '') !== '' ? (int) $m[5] : 0;

        if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
            throw new InvalidArgumentException('Parâmetros de dados fora do intervalo permitido.');
        }
        if ($keepMode !== null && ($keepCount < 1 || $keepCount > $count)) {
            throw new InvalidArgumentException('Quantidade de dados mantidos (kh/kl) inválida.');
        }

        $rolls = [];
        for ($i = 0; $i < $count; $i++) {
            $rolls[] = random_int(1, $sides);
        }

        $kept = $rolls;
        $dropped = [];
        if ($keepMode !== null) {
            $sorted = $rolls;
            $keepMode === 'kh' ? rsort($sorted) : sort($sorted);
            $kept = array_slice($sorted, 0, $keepCount);

            $remaining = $rolls;
            foreach ($kept as $value) {
                $idx = array_search($value, $remaining, true);
                if ($idx !== false) {
                    unset($remaining[$idx]);
                }
            }
            $dropped = array_values($remaining);
        }

        $sum = array_sum($kept) + $modifier;

        $detail = implode(' + ', $kept);
        if ($dropped !== []) {
            $detail .= ' (descartado: '.implode(', ', $dropped).')';
        }
        if ($modifier !== 0) {
            $detail .= ($modifier > 0 ? ' + ' : ' - ').abs($modifier);
        }
        $detail .= ' = '.$sum;

        $normalized = ($count === 1 ? 'd' : $count.'d').$sides;
        if ($keepMode !== null) {
            $normalized .= $keepMode.$keepCount;
        }
        if ($modifier > 0) {
            $normalized .= '+'.$modifier;
        } elseif ($modifier < 0) {
            $normalized .= (string) $modifier;
        }

        // "Natural" só faz sentido pra um único d20 mantido (roll normal, ou vantagem/desvantagem kh1/kl1).
        $natural = ($sides === 20 && count($kept) === 1) ? $kept[0] : null;

        return [
            'formula' => $normalized,
            'total' => $sum,
            'detail' => $detail,
            'rolls' => $rolls,
            'kept' => $kept,
            'natural' => $natural,
            'critical' => $natural === 20,
            'fumble' => $natural === 1,
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
