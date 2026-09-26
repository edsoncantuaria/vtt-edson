<?php

namespace App\Support\Dnd;

use Illuminate\Support\Str;

/** Only automate unconditional 5e consequences; conditional saves/position remain GM decisions. */
final class ConditionRules
{
    public const CONDITIONS = [
        'blinded', 'charmed', 'deafened', 'exhaustion', 'frightened', 'grappled', 'incapacitated',
        'invisible', 'paralyzed', 'petrified', 'poisoned', 'prone', 'restrained', 'stunned', 'unconscious',
    ];

    private const ALIASES = [
        'envenenado' => 'poisoned', 'contido' => 'restrained', 'caido' => 'prone',
        'cego' => 'blinded', 'paralisado' => 'paralyzed', 'atordoado' => 'stunned',
        'inconsciente' => 'unconscious', 'agarrado' => 'grappled', 'incapacitado' => 'incapacitated',
        'petrificado' => 'petrified', 'invisivel' => 'invisible', 'amedrontado' => 'frightened',
    ];

    public static function normalize(string $condition): string
    {
        $key = strtolower(Str::ascii(trim($condition)));

        return self::ALIASES[$key] ?? $key;
    }

    public static function names(array $system): array
    {
        return array_values(array_unique(array_map(self::normalize(...), $system['conditions'] ?? [])));
    }

    public static function apply(array $system): array
    {
        $conditions = self::names($system);
        if (array_intersect($conditions, ['restrained', 'grappled'])) {
            $system['speed'] = 0;
        }

        return $system;
    }

    /** Advantage and disadvantage cancel rather than stacking. */
    public static function mode(array $system, string $context, string $requested = 'normal', ?string $ability = null): array
    {
        $conditions = self::names($system);
        $disadvantage = match ($context) {
            'attack' => (bool) array_intersect($conditions, ['poisoned', 'restrained', 'prone', 'blinded']),
            'ability', 'skill' => in_array('poisoned', $conditions, true),
            'save' => $ability === 'dex' && in_array('restrained', $conditions, true),
            default => false,
        };
        $advantage = $requested === 'advantage';
        $disadvantage = $disadvantage || $requested === 'disadvantage';
        $mode = $advantage && $disadvantage ? 'normal' : ($advantage ? 'advantage' : ($disadvantage ? 'disadvantage' : 'normal'));

        return ['mode' => $mode, 'sources' => array_values(array_filter($conditions, fn ($condition) => match ($context) {
            'attack' => in_array($condition, ['poisoned', 'restrained', 'prone', 'blinded'], true),
            'ability', 'skill' => $condition === 'poisoned',
            'save' => $ability === 'dex' && $condition === 'restrained',
            default => false,
        }))];
    }

    public static function attackMode(array $attacker, ?array $target, string $requested, ?float $distanceFeet = null): array
    {
        $source = self::mode($attacker, 'attack');
        $attackerConditions = self::names($attacker);
        $targetConditions = $target === null ? [] : self::names($target);
        $advantage = $requested === 'advantage' || in_array('invisible', $attackerConditions, true)
            || (bool) array_intersect($targetConditions, ['restrained', 'blinded', 'paralyzed', 'stunned', 'unconscious'])
            || (in_array('prone', $targetConditions, true) && $distanceFeet !== null && $distanceFeet <= 5);
        $disadvantage = $requested === 'disadvantage' || $source['mode'] === 'disadvantage'
            || in_array('invisible', $targetConditions, true)
            || (in_array('prone', $targetConditions, true) && $distanceFeet !== null && $distanceFeet > 5);

        return ['mode' => $advantage && $disadvantage ? 'normal' : ($advantage ? 'advantage' : ($disadvantage ? 'disadvantage' : 'normal')),
            'sources' => array_values(array_unique([...$source['sources'], ...array_intersect($attackerConditions, ['invisible']),
                ...array_intersect($targetConditions, ['restrained', 'blinded', 'paralyzed', 'stunned', 'unconscious', 'prone', 'invisible'])]))];
    }

    public static function canAct(array $system): bool
    {
        return ! array_intersect(self::names($system), ['incapacitated', 'paralyzed', 'stunned', 'unconscious', 'petrified']);
    }
}
