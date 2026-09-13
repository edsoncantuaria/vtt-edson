<?php

namespace App\Support\Dnd;

use App\Models\ActiveEffect;
use App\Models\Actor;
use App\Models\Combat;

final class ActiveEffectEngine
{
    private const NUMERIC_PATHS = [
        'ac', 'speed', 'proficiencyBonus',
        'abilities.str.score', 'abilities.dex.score', 'abilities.con.score',
        'abilities.int.score', 'abilities.wis.score', 'abilities.cha.score',
        'saves.str.bonus', 'saves.dex.bonus', 'saves.con.bonus',
        'saves.int.bonus', 'saves.wis.bonus', 'saves.cha.bonus',
        'senses.darkvision', 'hp.max',
    ];

    private const ROLL_PATHS = ['roll.attack', 'roll.damage', 'roll.save', 'roll.skill', 'roll.initiative', 'spell.saveDc'];

    /** @param iterable<ActiveEffect> $effects */
    public function apply(array $system, iterable $effects): array
    {
        foreach ($effects as $effect) {
            if (! $effect->active) {
                continue;
            }
            foreach ($effect->modifiers as $modifier) {
                if (! in_array($modifier['path'], self::NUMERIC_PATHS, true) || ! is_numeric($modifier['value'])) {
                    continue;
                }
                $path = (string) $modifier['path'];
                $current = data_get($system, $path);
                if (! is_numeric($current)) {
                    continue;
                }
                $value = (float) $modifier['value'];
                $next = match ($modifier['mode']) {
                    'override' => $value,
                    'multiply' => (float) $current * $value,
                    default => (float) $current + $value,
                };
                data_set($system, $path, $next);
            }
            foreach ($effect->conditions as $condition) {
                if (trim($condition) !== '' && ! in_array($condition, $system['conditions'] ?? [], true)) {
                    $system['conditions'][] = mb_substr(trim($condition), 0, 120);
                }
            }
        }

        return $system;
    }

    /** @param iterable<ActiveEffect> $effects */
    public function rollModifier(iterable $effects, string $path): float
    {
        if (! in_array($path, self::ROLL_PATHS, true)) {
            return 0;
        }
        $value = 0.0;
        foreach ($effects as $effect) {
            if (! $effect->active) {
                continue;
            }
            foreach ($effect->modifiers as $modifier) {
                if ($modifier['path'] !== $path || ! is_numeric($modifier['value'])) {
                    continue;
                }
                $amount = (float) $modifier['value'];
                $value = match ($modifier['mode']) {
                    'override' => $amount,
                    'multiply' => $value * $amount,
                    default => $value + $amount,
                };
            }
        }

        return $value;
    }

    public function formulaSuffix(iterable $effects, string $path): string
    {
        $modifier = (int) round($this->rollModifier($effects, $path));
        $parts = $modifier !== 0 ? [($modifier > 0 ? '+' : '').$modifier] : [];
        foreach ($effects as $effect) {
            if (! $effect->active) {
                continue;
            }
            foreach ($effect->modifiers ?? [] as $entry) {
                if (($entry['path'] ?? null) !== $path || ! is_string($entry['value'] ?? null)) {
                    continue;
                }
                $value = str_replace(' ', '', $entry['value']);
                if (($entry['mode'] ?? 'add') === 'add' && preg_match('/^\d*d\d+(?:[+-]\d+)?$/i', $value)) {
                    $parts[] = '+'.$value;
                }
            }
        }

        return implode('', $parts);
    }

    public function applyFormulaModifier(string $formula, iterable $effects, string $path): string
    {
        return $formula.$this->formulaSuffix($effects, $path);
    }

    public function effectiveSystem(Actor $actor): array
    {
        return $this->apply($actor->system, $actor->activeEffects()->get());
    }

    public function advanceRound(Combat $combat): void
    {
        $actorIds = $combat->participants()->whereNotNull('actor_id')->pluck('actor_id');
        ActiveEffect::query()->whereIn('actor_id', $actorIds)->where('active', true)->get()->each(function (ActiveEffect $effect) {
            $duration = $effect->duration ?? [];
            if (($duration['unit'] ?? null) !== 'rounds') {
                return;
            }
            $remaining = max(0, (int) ($duration['remaining'] ?? 0) - 1);
            $duration['remaining'] = $remaining;
            $effect->duration = $duration;
            if ($remaining === 0) {
                $effect->active = false;
            }
            $effect->save();
        });
    }

    public function clearForRest(Actor $actor, string $rest): void
    {
        $units = $rest === 'long' ? ['until-short-rest', 'until-long-rest'] : ['until-short-rest'];
        $actor->activeEffects()->get()->each(function (ActiveEffect $effect) use ($units) {
            if (in_array(($effect->duration['unit'] ?? null), $units, true)) {
                $effect->active = false;
                $effect->save();
            }
        });
    }

    public static function validModifier(array $modifier): bool
    {
        $path = $modifier['path'] ?? null;
        $mode = $modifier['mode'] ?? 'add';
        $value = $modifier['value'] ?? null;
        if (! in_array($path, self::modifierPaths(), true) || ! in_array($mode, ['add', 'multiply', 'override'], true)) {
            return false;
        }
        if (is_numeric($value)) {
            return (float) $value >= -10000 && (float) $value <= 10000;
        }

        return is_string($value)
            && in_array($path, self::ROLL_PATHS, true)
            && $mode === 'add'
            && preg_match('/^\d*d\d+(?:[+-]\d+)?$/i', str_replace(' ', '', $value)) === 1;
    }

    public static function numericPaths(): array
    {
        return self::NUMERIC_PATHS;
    }

    public static function modifierPaths(): array
    {
        return [...self::NUMERIC_PATHS, ...self::ROLL_PATHS];
    }
}
