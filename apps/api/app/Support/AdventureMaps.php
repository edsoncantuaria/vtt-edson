<?php

namespace App\Support;

final class AdventureMaps
{
    public static function extract(array $raw): array
    {
        $maps = [];
        $walk = function ($value) use (&$walk, &$maps) {
            if (! is_array($value)) {
                return;
            }
            if (($value['type'] ?? null) === 'image' && (in_array($value['imageType'] ?? '', ['map', 'mapPlayer'], true) || isset($value['mapRegions']))) {
                $maps[] = $value;
            }
            foreach ($value as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($raw);

        return array_map(function ($map, $index) use ($maps) {
            $href = $map['href'] ?? [];
            $url = ($href['type'] ?? '') === 'internal'
                ? 'https://5e.tools/img/'.implode('/', array_map('rawurlencode', explode('/', ltrim((string) ($href['path'] ?? ''), '/'))))
                : ($href['url'] ?? null);
            $title = $map['title'] ?? 'Mapa '.($index + 1);
            if (isset($map['mapParent']['id'])) {
                foreach ($maps as $parent) {
                    if (($parent['id'] ?? null) === $map['mapParent']['id']) {
                        $title = ($parent['title'] ?? $title).' · jogadores';
                    }
                }
            }

            $regions = self::regions($map['mapRegions'] ?? []);
            $encounters = array_values(array_filter(array_map(fn ($region) => self::encounter($region), $regions)));
            $mapEncounter = $map['encounter'] ?? $map['encounters'] ?? $map['creatures'] ?? null;
            if ($mapEncounter !== null) {
                $encounters[] = ['regionId' => null, 'label' => mb_substr((string) $title, 0, 160), 'upstream' => self::bounded($mapEncounter)];
            }

            return [
                'index' => $index,
                'title' => $title,
                'url' => $url,
                'player' => ($map['imageType'] ?? '') === 'mapPlayer',
                'grid' => is_array($map['grid'] ?? null) ? $map['grid'] : [],
                'pins' => array_values(array_filter(array_map(fn ($region) => self::pin($region), $regions))),
                'encounters' => $encounters,
                'metadata' => array_filter([
                    'id' => $map['id'] ?? null,
                    'imageType' => $map['imageType'] ?? null,
                    'width' => is_numeric($map['width'] ?? null) ? (float) $map['width'] : null,
                    'height' => is_numeric($map['height'] ?? null) ? (float) $map['height'] : null,
                    'regionCount' => count($regions),
                ], fn ($value) => $value !== null),
            ];
        }, $maps, array_keys($maps));
    }

    private static function regions(mixed $regions): array
    {
        if (! is_array($regions)) {
            return [];
        }

        return array_values(array_filter($regions, 'is_array'));
    }

    private static function pin(array $region): ?array
    {
        $label = $region['name'] ?? $region['title'] ?? $region['area'] ?? $region['id'] ?? null;
        $points = $region['points'] ?? $region['polygon'] ?? null;
        if ($label === null && ! is_array($points)) {
            return null;
        }

        return array_filter([
            'id' => isset($region['id']) ? (string) $region['id'] : null,
            'label' => is_scalar($label) ? mb_substr((string) $label, 0, 160) : null,
            'points' => self::numericPoints($points),
        ], fn ($value) => $value !== null && $value !== []);
    }

    private static function encounter(array $region): ?array
    {
        $value = $region['encounter'] ?? $region['encounters'] ?? $region['creatures'] ?? null;
        if ($value === null) {
            return null;
        }

        return [
            'regionId' => isset($region['id']) ? (string) $region['id'] : null,
            'label' => mb_substr((string) ($region['name'] ?? $region['title'] ?? $region['area'] ?? 'Encontro'), 0, 160),
            'upstream' => self::bounded($value),
        ];
    }

    private static function numericPoints(mixed $points): ?array
    {
        if (! is_array($points)) {
            return null;
        }
        $result = [];
        foreach (array_slice($points, 0, 200) as $point) {
            if (is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $result[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $result ?: null;
    }

    private static function bounded(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 4) {
            return null;
        }
        if (is_scalar($value) || $value === null) {
            return is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        if (! is_array($value)) {
            return null;
        }
        $result = [];
        foreach (array_slice($value, 0, 50, true) as $key => $child) {
            $result[is_int($key) ? $key : mb_substr((string) $key, 0, 80)] = self::bounded($child, $depth + 1);
        }

        return $result;
    }
}
