<?php

namespace App\Support\Dnd;

use App\Models\CatalogEntry;
use Illuminate\Support\Str;

final class MonsterActorFactory
{
    /** @return array<string, mixed> */
    public static function fromCatalog(CatalogEntry $entry): array
    {
        $data = $entry->data ?? [];
        $raw = is_array($data['raw'] ?? null) ? $data['raw'] : $data;
        $system = ActorStateFactory::monster();
        $system['statBlock'] = $raw;
        $system['damageTraits'] = ['resist' => [], 'immune' => [], 'vulnerable' => []];

        foreach (['str', 'dex', 'con', 'int', 'wis', 'cha'] as $ability) {
            $score = $raw[$ability] ?? data_get($data, 'abilities.'.$ability);
            if (is_numeric($score)) {
                $system['abilities'][$ability]['score'] = (int) $score;
            }
        }

        $hp = $data['hit_points'] ?? data_get($raw, 'hp.average') ?? 10;
        if (is_string($hp) && preg_match('/\((\d+)\)/', $hp, $match)) {
            $hp = (int) $match[1];
        }
        $hp = max(1, (int) $hp);
        $system['hp'] = ['value' => $hp, 'max' => $hp, 'temp' => 0];

        $ac = $data['armor_class'] ?? null;
        if ($ac === null) {
            $rawAc = $raw['ac'] ?? 10;
            if (is_array($rawAc)) {
                $first = $rawAc[0] ?? 10;
                $ac = is_array($first) ? ($first['ac'] ?? 10) : $first;
            } else {
                $ac = $rawAc;
            }
        }
        $system['ac'] = is_numeric($ac) ? (int) $ac : 10;

        $speed = $data['speed'] ?? $raw['speed'] ?? 30;
        if (is_array($speed)) {
            $speed = $speed['walk'] ?? 30;
        }
        if (is_string($speed) && preg_match('/\d+/', $speed, $match)) {
            $speed = (int) $match[0];
        }
        $system['speed'] = is_numeric($speed) ? (int) $speed : 30;

        foreach (($raw['save'] ?? []) as $ability => $bonus) {
            if (isset($system['saves'][$ability]) && is_string($bonus) && preg_match('/^[+-]?\d+$/', $bonus)) {
                $system['saves'][$ability]['bonus'] = (int) $bonus;
            }
        }

        foreach (['resist', 'immune', 'vulnerable'] as $kind) {
            foreach (($raw[$kind] ?? []) as $value) {
                if (is_string($value) && in_array($value, CombatRules::DAMAGE_TYPES, true)) {
                    $system['damageTraits'][$kind][] = $value;
                }
            }
        }

        foreach (['trait', 'action', 'bonus', 'reaction', 'legendary', 'mythic'] as $section) {
            foreach (($raw[$section] ?? []) as $action) {
                if (! is_array($action) || ! is_string($action['name'] ?? null)) {
                    continue;
                }
                $serialized = json_encode($action['entries'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
                preg_match('/\\{@hit ([+-]?\d+)\\}/', $serialized, $hit);
                preg_match_all('/\\{@damage ([^}|]+)/', $serialized, $damages);
                $damage = count($damages[1]) === 1 ? str_replace(' ', '', $damages[1][0]) : null;
                if (! isset($hit[1]) && $damage === null) {
                    continue;
                }
                $system['actions'][] = array_filter([
                    'id' => (string) Str::uuid(),
                    'name' => $action['name'],
                    'kind' => 'attack',
                    'attackFormula' => isset($hit[1]) ? '1d20'.(((int) $hit[1]) >= 0 ? '+' : '').(int) $hit[1] : null,
                    'damageFormula' => $damage,
                    'economy' => $section === 'bonus' ? 'bonus' : ($section === 'reaction' ? 'reaction' : ($section === 'action' ? 'action' : 'other')),
                    'description' => self::flattenText($action['entries'] ?? []),
                ], fn ($value) => $value !== null && $value !== '');
            }
        }

        $features = [];
        foreach (($data['actions'] ?? []) as $action) {
            if (is_array($action) && is_string($action['name'] ?? null)) {
                $features[] = ['id' => (string) Str::uuid(), 'name' => $action['name'], 'description' => $action['desc'] ?? null];
            }
        }
        $system['features'] = $features;
        $type = is_string($data['type'] ?? null) ? $data['type'] : (is_string($raw['type'] ?? null) ? $raw['type'] : '');
        $alignment = is_string($data['alignment'] ?? null) ? $data['alignment'] : '';
        $system['bio']['race'] = $type;
        $system['bio']['alignment'] = $alignment;
        $system['bio']['notes'] = trim(implode("\n\n", array_filter([
            implode(' · ', array_filter([$data['size'] ?? null, $type, $alignment])),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ])));
        if (! empty($data['tokenUrl'])) {
            $system['tokenImageUrl'] = '/api/catalog-media/'.$entry->id.'/token';
        }

        return $system;
    }

    public static function tokenSize(CatalogEntry $entry): float
    {
        $size = $entry->data['raw']['size'] ?? $entry->data['size'] ?? null;
        if (is_array($size)) {
            $size = $size[0] ?? null;
        }

        return match (strtoupper((string) $size)) {
            'T' => 0.5,
            'L' => 2.0,
            'H' => 3.0,
            'G' => 4.0,
            default => 1.0,
        };
    }

    private static function flattenText(mixed $value): string
    {
        if (is_string($value)) {
            return preg_replace('/\\{@\w+\s+([^}|]+)(?:\|[^}]*)?\\}/', '$1', $value) ?? $value;
        }
        if (! is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $item) {
            $parts[] = self::flattenText($item);
        }

        return trim(implode("\n", array_filter($parts)));
    }
}
