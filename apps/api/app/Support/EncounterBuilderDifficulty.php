<?php

namespace App\Support;

use App\Models\CatalogEntry;

final class EncounterBuilderDifficulty
{
    private const XP_BY_CR = [
        '0' => 10, '1/8' => 25, '1/4' => 50, '1/2' => 100, '1' => 200, '2' => 450,
        '3' => 700, '4' => 1100, '5' => 1800, '6' => 2300, '7' => 2900, '8' => 3900,
        '9' => 5000, '10' => 5900, '11' => 7200, '12' => 8400, '13' => 10000, '14' => 11500,
        '15' => 13000, '16' => 15000, '17' => 18000, '18' => 20000, '19' => 22000, '20' => 25000,
        '21' => 33000, '22' => 41000, '23' => 50000, '24' => 62000, '25' => 75000, '26' => 90000,
        '27' => 105000, '28' => 120000, '29' => 135000, '30' => 155000,
    ];

    private const THRESHOLDS = [
        1 => [25, 50, 75, 100], 2 => [50, 100, 150, 200], 3 => [75, 150, 225, 400], 4 => [125, 250, 375, 500],
        5 => [250, 500, 750, 1100], 6 => [300, 600, 900, 1400], 7 => [350, 750, 1100, 1700], 8 => [450, 900, 1400, 2100],
        9 => [550, 1100, 1600, 2400], 10 => [600, 1200, 1900, 2800], 11 => [800, 1600, 2400, 3600], 12 => [1000, 2000, 3000, 4500],
        13 => [1100, 2200, 3400, 5100], 14 => [1250, 2500, 3800, 5700], 15 => [1400, 2800, 4300, 6400], 16 => [1600, 3200, 4800, 7200],
        17 => [2000, 3900, 5900, 8800], 18 => [2100, 4200, 6300, 9500], 19 => [2400, 4900, 7300, 10900], 20 => [2800, 5700, 8500, 12700],
    ];

    /** 2024 DMG, XP Budget per Character: low / moderate / high. */
    private const THRESHOLDS_2024 = [
        1 => [50, 75, 100], 2 => [100, 150, 200], 3 => [150, 225, 400], 4 => [250, 375, 500],
        5 => [500, 750, 1100], 6 => [600, 1000, 1400], 7 => [750, 1300, 1700], 8 => [1000, 1700, 2100],
        9 => [1300, 2000, 2600], 10 => [1600, 2300, 3100], 11 => [1900, 2900, 4100], 12 => [2200, 3700, 4700],
        13 => [2600, 4200, 5400], 14 => [2900, 4900, 6200], 15 => [3300, 5400, 7800], 16 => [3800, 6100, 9800],
        17 => [4500, 7200, 11700], 18 => [5000, 8700, 14200], 19 => [5500, 10700, 17200], 20 => [6400, 13200, 22000],
    ];

    public static function creature(CatalogEntry $entry, int $quantity): array
    {
        $raw = $entry->data['raw'] ?? $entry->data;
        $cr = $raw['cr'] ?? $entry->data['cr'] ?? null;
        if (is_array($cr)) {
            $cr = $cr['cr'] ?? $cr['value'] ?? null;
        }
        $cr = $cr === null ? null : (string) $cr;
        $xp = (int) ($raw['xp'] ?? $entry->data['xp'] ?? ($cr !== null ? (self::XP_BY_CR[$cr] ?? 0) : 0));

        return [
            'catalogEntryId' => $entry->id,
            'name' => $entry->name,
            'source' => $entry->source,
            'cr' => $cr,
            'xp' => $xp,
            'quantity' => $quantity,
        ];
    }

    public static function summarize(array $creatures, array $party, string $ruleset = '5e-2014'): array
    {
        $count = array_sum(array_column($creatures, 'quantity'));
        $total = array_sum(array_map(fn ($c) => ((int) $c['xp']) * ((int) $c['quantity']), $creatures));
        if ($ruleset === '5e-2024') {
            $thresholds = ['low' => 0, 'moderate' => 0, 'high' => 0];
            foreach ($party as $member) {
                $level = is_array($member) ? (int) ($member['level'] ?? 0) : (int) $member;
                if (! isset(self::THRESHOLDS_2024[$level])) {
                    continue;
                }
                foreach (array_keys($thresholds) as $index => $key) {
                    $thresholds[$key] += self::THRESHOLDS_2024[$level][$index];
                }
            }
            $rating = 'unrated';
            if (array_sum($thresholds) > 0) {
                $rating = $total >= $thresholds['high'] ? 'high'
                    : ($total >= $thresholds['moderate'] ? 'moderate'
                        : ($total >= $thresholds['low'] ? 'low' : 'trivial'));
            }

            return [
                'count' => $count,
                'total' => $total,
                'multiplier' => 1,
                'adjusted' => $total,
                'thresholds' => $thresholds,
                'rating' => $rating,
                'ruleset' => $ruleset,
            ];
        }
        $multiplier = match (true) {
            $count <= 1 => 1,
            $count === 2 => 1.5,
            $count <= 6 => 2,
            $count <= 10 => 2.5,
            $count <= 14 => 3,
            default => 4,
        };
        $adjusted = (int) round($total * $multiplier);
        $thresholds = ['easy' => 0, 'medium' => 0, 'hard' => 0, 'deadly' => 0];
        foreach ($party as $member) {
            $level = is_array($member) ? (int) ($member['level'] ?? 0) : (int) $member;
            if (! isset(self::THRESHOLDS[$level])) {
                continue;
            }
            foreach (array_keys($thresholds) as $index => $key) {
                $thresholds[$key] += self::THRESHOLDS[$level][$index];
            }
        }
        $rating = 'unrated';
        if (array_sum($thresholds) > 0) {
            $rating = $adjusted >= $thresholds['deadly'] ? 'deadly'
                : ($adjusted >= $thresholds['hard'] ? 'hard'
                    : ($adjusted >= $thresholds['medium'] ? 'medium'
                        : ($adjusted >= $thresholds['easy'] ? 'easy' : 'trivial')));
        }

        return [...compact('count', 'total', 'multiplier', 'adjusted', 'thresholds', 'rating'), 'ruleset' => $ruleset];
    }
}
