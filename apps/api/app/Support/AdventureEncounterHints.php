<?php

namespace App\Support;

final class AdventureEncounterHints
{
    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12,
    ];

    public static function extract(array $raw): array
    {
        $hints = [];
        self::walk($raw, null, $hints);

        return array_values($hints);
    }

    private static function walk(mixed $value, ?string $section, array &$hints): void
    {
        if (is_string($value)) {
            self::readText($value, $section, $hints);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $nextSection = $section;
        foreach (['name', 'title'] as $field) {
            if (isset($value[$field]) && is_string($value[$field]) && trim($value[$field]) !== '') {
                $nextSection = mb_substr(trim($value[$field]), 0, 160);
                break;
            }
        }

        foreach ($value as $child) {
            self::walk($child, $nextSection, $hints);
        }
    }

    private static function readText(string $text, ?string $section, array &$hints): void
    {
        if (! str_contains($text, '{@creature ')) {
            return;
        }

        $areas = [];
        if (preg_match_all('/\{@area\s+([^}|]+)(?:\|[^}]*)?\}/iu', $text, $areaMatches)) {
            $areas = array_values(array_unique(array_map(fn ($area) => trim((string) $area), $areaMatches[1])));
        }

        if (! preg_match_all('/\{@creature\s+([^}|]+)(?:\|([^}|]*))?(?:\|[^}]*)?\}/iu', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($matches[1] as $index => $match) {
            $name = trim((string) $match[0]);
            if ($name === '') {
                continue;
            }
            $source = trim((string) ($matches[2][$index][0] ?? ''));
            $offset = (int) ($matches[0][$index][1] ?? 0);
            $prefix = mb_substr($text, max(0, $offset - 80), min(80, $offset));
            $quantity = self::quantityFromPrefix($prefix);
            $area = $areas[0] ?? null;
            $label = $area ?: $section ?: 'Encontro sugerido';
            $key = mb_strtolower($label.'|'.$name.'|'.$source);

            if (! isset($hints[$key])) {
                $hints[$key] = array_filter([
                    'label' => $label,
                    'area' => $area,
                    'section' => $section,
                    'creature' => ['name' => $name, 'source' => $source ?: null],
                    'quantity' => $quantity,
                    'confidence' => $quantity > 1 || $area ? 'high' : 'medium',
                ], fn ($item) => $item !== null);
            } else {
                $hints[$key]['quantity'] += $quantity;
            }
        }
    }

    private static function quantityFromPrefix(string $prefix): int
    {
        if (preg_match('/(?:^|[\s,(])([1-9]\d?)\s*$/u', $prefix, $match)) {
            return min(50, (int) $match[1]);
        }
        if (preg_match('/\b('.implode('|', array_keys(self::NUMBER_WORDS)).')\s*$/iu', $prefix, $match)) {
            return self::NUMBER_WORDS[mb_strtolower($match[1])] ?? 1;
        }

        return 1;
    }
}
