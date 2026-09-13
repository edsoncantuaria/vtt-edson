<?php

namespace App\Support\Dnd;

use Illuminate\Support\Str;

/**
 * Mapeia o JSON da API pública do Open5e (v1 ou v2) pro formato normalizado
 * das tabelas compendium_*. Puramente funcional (sem I/O) pra dar pra testar sem rede.
 *
 * A v2 usa `key` e objetos para campos como school; a v1 usa `slug` e strings.
 */
final class SrdImporter
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{slug: string, name: string, level: int, school: ?string, data: array}
     */
    public static function mapSpell(array $raw): array
    {
        $name = (string) ($raw['name'] ?? 'Magia sem nome');
        $level = $raw['level_int'] ?? self::parseLeadingInt($raw['level'] ?? null) ?? 0;

        return [
            'slug' => (string) ($raw['slug'] ?? $raw['key'] ?? Str::slug($name)),
            'name' => $name,
            'level' => (int) $level,
            'school' => isset($raw['school']) ? Str::lower((string) (is_array($raw['school']) ? ($raw['school']['key'] ?? $raw['school']['name'] ?? '') : $raw['school'])) : null,
            'data' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{slug: string, name: string, type: string, data: array}
     */
    public static function mapItem(array $raw, string $type): array
    {
        $name = (string) ($raw['name'] ?? 'Item sem nome');

        return [
            'slug' => (string) ($raw['slug'] ?? $raw['key'] ?? Str::slug($name)),
            'name' => $name,
            'type' => $type,
            'data' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{slug: string, name: string, challenge_rating: ?string, data: array}
     */
    public static function mapMonster(array $raw): array
    {
        $name = (string) ($raw['name'] ?? 'Monstro sem nome');

        return [
            'slug' => (string) ($raw['slug'] ?? $raw['key'] ?? Str::slug($name)),
            'name' => $name,
            'challenge_rating' => isset($raw['challenge_rating']) ? (string) $raw['challenge_rating'] : null,
            'data' => $raw,
        ];
    }

    private static function parseLeadingInt(?string $text): ?int
    {
        if ($text === null) {
            return null;
        }

        return preg_match('/\d+/', $text, $m) ? (int) $m[0] : null;
    }
}
