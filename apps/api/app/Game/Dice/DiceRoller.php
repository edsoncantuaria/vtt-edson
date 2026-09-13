<?php

namespace App\Game\Dice;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class DiceRoller
{
    private const DICE_TERM = '/^(\d*)d(\d+)(?:(kh|kl)(\d*))?$/i';

    /**
     * Rolls a bounded additive expression such as 2d20kh1+5, 8d6, or d20+d4+3.
     * The first retained d20 remains the natural roll used for crit/fumble semantics.
     *
     * @return array{formula:string,total:int,detail:string,rolls:list<int>,kept:list<int>,natural:int|null,critical:bool,fumble:bool}
     */
    public function roll(string $formula): array
    {
        $expression = preg_replace('/\s+/', '', trim($formula)) ?? '';
        if ($expression === '' || ! preg_match('/^[+-]?(?:\d*d\d+(?:(?:kh|kl)\d*)?|\d+)(?:[+-](?:\d*d\d+(?:(?:kh|kl)\d*)?|\d+))*$/i', $expression)) {
            throw new InvalidArgumentException('Fórmula de dados inválida. Use dados e somas simples (ex.: 2d6+3, d20+d4+5, 2d20kh1+5).');
        }

        preg_match_all('/([+-]?)(\d*d\d+(?:(?:kh|kl)\d*)?|\d+)/i', $expression, $matches, PREG_SET_ORDER);
        if (count($matches) > 20) {
            throw new InvalidArgumentException('A fórmula contém termos demais.');
        }

        $total = 0;
        $allRolls = [];
        $allKept = [];
        $detailParts = [];
        $normalized = '';
        $natural = null;
        $diceCount = 0;

        foreach ($matches as $index => $match) {
            $sign = $match[1] === '-' ? -1 : 1;
            $term = $match[2];
            $prefix = $index === 0 ? ($sign < 0 ? '-' : '') : ($sign < 0 ? '-' : '+');

            if (preg_match(self::DICE_TERM, $term, $dice)) {
                $count = $dice[1] !== '' ? (int) $dice[1] : 1;
                $sides = (int) $dice[2];
                $keepMode = ($dice[3] ?? '') !== '' ? Str::lower($dice[3]) : null;
                $keepCount = $keepMode !== null ? (($dice[4] ?? '') !== '' ? (int) $dice[4] : 1) : null;
                if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
                    throw new InvalidArgumentException('Parâmetros de dados fora do intervalo permitido.');
                }
                $diceCount += $count;
                if ($diceCount > 100) {
                    throw new InvalidArgumentException('A fórmula excede 100 dados.');
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
                        $position = array_search($value, $remaining, true);
                        if ($position !== false) {
                            unset($remaining[$position]);
                        }
                    }
                    $dropped = array_values($remaining);
                }

                $subtotal = array_sum($kept) * $sign;
                $total += $subtotal;
                array_push($allRolls, ...$rolls);
                array_push($allKept, ...$kept);
                if ($natural === null && $sign > 0 && $sides === 20 && count($kept) === 1) {
                    $natural = $kept[0];
                }
                $normalizedTerm = ($count === 1 ? 'd' : $count.'d').$sides;
                if ($keepMode !== null) {
                    $normalizedTerm .= $keepMode.$keepCount;
                }
                $normalized .= $prefix.$normalizedTerm;
                $part = implode(' + ', $kept);
                if ($dropped !== []) {
                    $part .= ' (descartado: '.implode(', ', $dropped).')';
                }
                $detailParts[] = ($sign < 0 ? '-(' : '').$part.($sign < 0 ? ')' : '');
            } else {
                $value = (int) $term;
                if ($value > 100000) {
                    throw new InvalidArgumentException('Modificador fora do intervalo permitido.');
                }
                $total += $sign * $value;
                $normalized .= $prefix.$value;
                $detailParts[] = ($sign < 0 ? '-' : '+').$value;
            }
        }

        $detail = trim(implode(' + ', $detailParts));
        $detail = preg_replace('/\+ -/', '- ', $detail) ?? $detail;
        $detail .= ' = '.$total;

        return [
            'formula' => $normalized,
            'total' => $total,
            'detail' => $detail,
            'rolls' => $allRolls,
            'kept' => $allKept,
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
