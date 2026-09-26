<?php

namespace App\Support\Dnd;

use InvalidArgumentException;

/** One authority for legacy slot, inspiration and custom pools. Values remain in the canonical actor system. */
final class ResourcePool
{
    // Inspiration is a reserved built-in pool and must not be duplicated in custom resources.
    public const KINDS = ['homebrew', 'rage', 'focus', 'ki', 'channel-divinity'];

    private const POLICIES = ['none', 'one', 'full'];

    public static function recovery(array $resource, string $edition): array
    {
        if (isset($resource['recovery'])) {
            return $resource['recovery'];
        }

        return match ($resource['kind'] ?? 'homebrew') {
            'rage' => ['short' => $edition === '5e-2024' ? 'one' : 'none', 'long' => 'full'],
            'focus', 'ki' => ['short' => 'full', 'long' => 'full'],
            'channel-divinity' => ['short' => $edition === '5e-2024' ? 'one' : 'full', 'long' => 'full'],
            default => ['short' => ($resource['reset'] ?? 'manual') === 'short' ? 'full' : 'none',
                'long' => in_array($resource['reset'] ?? 'manual', ['short', 'long'], true) ? 'full' : 'none'],
        };
    }

    /** @return list<array<string,mixed>> */
    public static function pools(array $system, string $edition, ?int $actorId = null, iterable $documents = []): array
    {
        $pools = [];
        foreach ($system['spells']['slots'] ?? [] as $level => $slot) {
            if ((int) ($slot['max'] ?? 0) <= 0) {
                continue;
            }
            $pools[] = self::row('slot:'.$level, 'spell-slot', 'Espaço nível '.$level, (int) $slot['max'],
                (int) $slot['used'], 'spells.slots', $edition, 1,
                self::recovery(['reset' => $slot['reset'] ?? 'long'], $edition), $actorId);
        }
        $pools[] = self::row('inspiration', 'inspiration', 'Inspiração', 1,
            ($system['inspiration'] ?? false) ? 0 : 1, 'system.inspiration', $edition, 1,
            ['short' => 'none', 'long' => 'none'], $actorId);
        foreach ($system['resources'] ?? [] as $resource) {
            $pools[] = self::row($resource['id'], $resource['kind'] ?? 'homebrew', $resource['name'],
                (int) $resource['max'], (int) $resource['used'], $resource['source'] ?? 'Ficha',
                $resource['edition'] ?? $edition, (int) ($resource['defaultCost'] ?? 1),
                self::recovery($resource, $edition), $actorId);
        }
        foreach ($documents as $document) {
            if (! $document->charges) {
                continue;
            }
            $charges = $document->charges;
            $pools[] = self::row('document:'.$document->id, 'document-charge', $document->name,
                (int) $charges['max'], (int) $charges['max'] - (int) $charges['value'],
                $document->source ?: 'Documento · '.$document->name, $edition, 1,
                self::recovery(['reset' => $charges['reset'] ?? 'manual'], $edition), $actorId);
        }

        return $pools;
    }

    private static function row(string $id, string $kind, string $name, int $max, int $used, string $source,
        string $edition, int $cost, array $recovery, ?int $actorId): array
    {
        return ['id' => $id, 'kind' => $kind, 'name' => $name, 'current' => $max - $used,
            'max' => $max, 'used' => $used, 'source' => $source, 'edition' => $edition,
            'defaultCost' => $cost, 'recovery' => $recovery, 'actorId' => $actorId];
    }

    /** Validate every cost before any dice/side effect. The return value is suitable for an action audit. */
    public static function quote(array $system, array $action, string $edition, ?array $documentCharges = null): array
    {
        $costs = [];
        if (isset($action['spellSlotLevel'])) {
            $key = (string) $action['spellSlotLevel'];
            $slot = $system['spells']['slots'][$key] ?? null;
            self::require($slot && (int) $slot['max'] - (int) $slot['used'] >= 1,
                'Espaço de magia nível '.$key.' esgotado. Faça um descanso compatível ou escolha outra ação.');
            $costs[] = ['id' => 'slot:'.$key, 'cost' => 1, 'before' => (int) $slot['max'] - (int) $slot['used']];
        }
        if (isset($action['resourceId'])) {
            $id = (string) $action['resourceId'];
            $resource = collect($system['resources'] ?? [])->firstWhere('id', $id);
            if ($id === 'inspiration') {
                $cost = (int) ($action['resourceCost'] ?? 1);
                self::require($cost === 1 && ($system['inspiration'] ?? false), 'Inspiração indisponível nesta ficha.');
                $costs[] = ['id' => 'inspiration', 'cost' => 1, 'before' => 1];
            } else {
                self::require($resource !== null, 'O recurso '.$id.' não existe nesta ficha.');
                self::require(! isset($resource['edition']) || $resource['edition'] === $edition,
                    'O recurso '.$resource['name'].' pertence a outra edição.');
                $cost = (int) ($action['resourceCost'] ?? $resource['defaultCost'] ?? 1);
                self::require($cost >= 1 && $cost <= 1000, 'O custo do recurso é inválido.');
                $available = (int) $resource['max'] - (int) $resource['used'];
                self::require($available >= $cost, 'Não há usos suficientes de '.$resource['name'].'.');
                $costs[] = ['id' => $id, 'cost' => $cost, 'before' => $available];
            }
        }
        if (isset($action['documentId'])) {
            self::require($documentCharges !== null, 'Este documento não possui cargas configuradas.');
            $cost = (int) ($action['chargeCost'] ?? 1);
            self::require($cost >= 1 && $cost <= 1000 && (int) ($documentCharges['value'] ?? 0) >= $cost,
                'Cargas insuficientes no item: precisa de '.$cost.', disponíveis '.($documentCharges['value'] ?? 0).'.');
            $costs[] = ['id' => 'document:'.$action['documentId'], 'cost' => $cost, 'before' => (int) $documentCharges['value']];
        }

        return $costs;
    }

    public static function spend(array $system, array $costs): array
    {
        foreach ($costs as $cost) {
            $id = $cost['id'];
            if (str_starts_with($id, 'slot:')) {
                $key = substr($id, 5);
                $system['spells']['slots'][$key]['used'] += $cost['cost'];
            } elseif ($id === 'inspiration') {
                $system['inspiration'] = false;
            } elseif (! str_starts_with($id, 'document:')) {
                foreach ($system['resources'] as &$resource) {
                    if ($resource['id'] === $id) {
                        $resource['used'] += $cost['cost'];
                        break;
                    }
                }
                unset($resource);
            }
        }

        return $system;
    }

    public static function rest(array $system, string $rest, string $edition): array
    {
        foreach ($system['spells']['slots'] ?? [] as $level => $slot) {
            $policy = self::recovery(['reset' => $slot['reset'] ?? 'long'], $edition)[$rest];
            $system['spells']['slots'][$level]['used'] = self::restore((int) $slot['used'], $policy);
        }
        foreach ($system['resources'] ?? [] as $index => $resource) {
            $policy = self::recovery($resource, $edition)[$rest];
            $system['resources'][$index]['used'] = self::restore((int) $resource['used'], $policy);
        }

        return $system;
    }

    public static function restore(int $used, string $policy): int
    {
        self::require(in_array($policy, self::POLICIES, true), 'Política de recuperação inválida.');

        return match ($policy) {
            'full' => 0,
            'one' => max(0, $used - 1),
            default => $used,
        };
    }

    public static function restDocument(array $charges, string $rest): array
    {
        $policy = self::recovery(['reset' => $charges['reset'] ?? 'manual'], '')[$rest];
        if (($charges['reset'] ?? null) === 'dawn') {
            return $charges; // Dawn requires a separate time/GM trigger, never an arbitrary long rest.
        }
        $charges['value'] = (int) $charges['max'] - self::restore((int) $charges['max'] - (int) $charges['value'], $policy);

        return $charges;
    }

    /** Level-up does not replenish spent resources; only explicit class tables change the maximum. */
    public static function advance(array $before, array $next, string $edition): array
    {
        foreach ($before['resources'] ?? [] as $index => $resource) {
            $scaling = $resource['scaling'] ?? null;
            if (! $scaling || isset($resource['edition']) && $resource['edition'] !== $edition) {
                continue;
            }
            $matching = collect($next['progression']['classes'] ?? [])->filter(fn ($class) => (isset($scaling['classId']) ? (int) ($class['classId'] ?? 0) === (int) $scaling['classId']
                    : ($class['name'] ?? null) === $scaling['className'])
                && (! isset($scaling['classSource']) || ($class['source'] ?? null) === $scaling['classSource']));
            // Ambiguous legacy names must never upgrade an unrelated class instance.
            $level = $matching->count() === 1 ? $matching->first()['level'] : null;
            $newMax = $scaling['byLevel'][(string) $level] ?? null;
            if ($newMax === null) {
                continue;
            }
            $next['resources'][$index]['max'] = (int) $newMax;
            $next['resources'][$index]['used'] = min((int) $newMax, (int) $resource['used']);
        }

        return $next;
    }

    /** Current available, not remaining spent: never go below zero or above max. */
    public static function adjust(array $system, string $id, int $current): array
    {
        if ($id === 'inspiration') {
            self::require(in_array($current, [0, 1], true), 'Inspiração deve ser zero ou um.');
            $system['inspiration'] = $current === 1;

            return $system;
        }
        if (str_starts_with($id, 'slot:')) {
            $level = substr($id, 5);
            $slot = $system['spells']['slots'][$level] ?? null;
            self::require($slot !== null && $current >= 0 && $current <= $slot['max'], 'Espaço de magia fora do limite.');
            $system['spells']['slots'][$level]['used'] = $slot['max'] - $current;

            return $system;
        }
        foreach ($system['resources'] ?? [] as $index => $resource) {
            if ($resource['id'] === $id) {
                self::require($current >= 0 && $current <= $resource['max'], $resource['name'].': valor fora do limite.');
                $system['resources'][$index]['used'] = $resource['max'] - $current;

                return $system;
            }
        }
        throw new InvalidArgumentException('Recurso não pertence a esta ficha.');
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
