<?php

namespace App\Support;

use App\Models\User;
use App\Support\Geometry\SegmentGeometry;

/** Server-authoritative scene perception. Never expose hidden/secret geometry before these checks pass. */
final class SceneVisibility
{
    /**
     * @param  array<int,array{actorId:?int,x:float|int,y:float|int,darkvision:int|float,passivePerception:int}>  $viewers
     */
    public static function tokens(array $state, User $user, array $viewers): array
    {
        $tokens = array_values($state['tokens'] ?? []);
        $dynamic = (bool) data_get($state, 'vision.dynamic', false);
        $darkness = (bool) data_get($state, 'vision.darkness', false);
        $blockers = self::blockers($state);

        return array_values(array_filter(array_map(function (array $token) use ($state, $user, $viewers, $dynamic, $darkness, $blockers) {
            if (($token['ownerUserId'] ?? null) === $user->id) {
                return $token;
            }

            $perceptible = ! $dynamic || self::visiblePoint($token, $state, $viewers, $blockers, $darkness);
            if (! $perceptible) {
                return null;
            }

            if ($token['hidden'] ?? false) {
                // Historical `hidden` remains GM-only unless the GM explicitly assigns a Stealth DC.
                $dc = isset($token['stealthDc']) ? (int) $token['stealthDc'] : null;
                if ($dc === null || ! self::passesPassivePerception($viewers, $dc)) {
                    return null;
                }
                $token['detected'] = true;
            }

            return $token;
        }, $tokens)));
    }

    /**
     * Returns a player-safe representation of doors. Undetected secret doors are indistinguishable from walls.
     *
     * @param  array<int,array{actorId:?int,x:float|int,y:float|int,darkvision:int|float,passivePerception:int}>  $viewers
     * @return array{doors:array,walls:array}
     */
    public static function doors(array $state, array $viewers): array
    {
        $doors = [];
        $walls = $state['walls'] ?? [];
        $dynamic = (bool) data_get($state, 'vision.dynamic', false);
        $darkness = (bool) data_get($state, 'vision.darkness', false);
        $blockers = self::blockers($state);

        foreach ($state['doors'] ?? [] as $door) {
            if (($door['state'] ?? 'normal') !== 'secret' || ($door['open'] ?? false)) {
                $doors[] = $door;

                continue;
            }

            $dc = isset($door['perceptionDc']) ? (int) $door['perceptionDc'] : null;
            $midpoint = ['x' => ($door['x1'] + $door['x2']) / 2, 'y' => ($door['y1'] + $door['y2']) / 2];
            $inSight = ! $dynamic || self::visiblePoint($midpoint, $state, $viewers, $blockers, $darkness, $door['id'] ?? null);
            if ($dc !== null && $inSight && self::passesPassivePerception($viewers, $dc)) {
                $doors[] = [...$door, 'detected' => true];

                continue;
            }

            $walls[] = [
                'id' => 'secret-door-'.($door['id'] ?? 'unknown'),
                'x1' => $door['x1'], 'y1' => $door['y1'],
                'x2' => $door['x2'], 'y2' => $door['y2'],
            ];
        }

        return compact('doors', 'walls');
    }

    private static function passesPassivePerception(array $viewers, int $dc): bool
    {
        foreach ($viewers as $viewer) {
            if (($viewer['passivePerception'] ?? 0) >= $dc) {
                return true;
            }
        }

        return false;
    }

    private static function visiblePoint(array $point, array $state, array $viewers, array $blockers, bool $darkness, ?string $ignoreDoorId = null): bool
    {
        foreach ($state['fog']['revealed'] ?? [] as $rect) {
            if ($point['x'] >= $rect['x'] && $point['x'] <= $rect['x'] + $rect['w'] && $point['y'] >= $rect['y'] && $point['y'] <= $rect['y'] + $rect['h']) {
                return true;
            }
        }

        $grid = max(1, (float) ($state['grid']['size'] ?? 70));
        $normalRadius = ($state['vision']['normalVisionFeet'] ?? 60) / 5 * $grid;
        foreach ($viewers as $viewer) {
            $distance = hypot($point['x'] - $viewer['x'], $point['y'] - $viewer['y']);
            if ($distance > $normalRadius) {
                continue;
            }
            if (self::lineBlocked($viewer, $point, $blockers, $ignoreDoorId)) {
                continue;
            }

            if (! $darkness) {
                return true;
            }

            $darkvisionRadius = max(0, (float) ($viewer['darkvision'] ?? 0)) / 5 * $grid;
            if ($darkvisionRadius > 0 && $distance <= $darkvisionRadius) {
                return true;
            }

            foreach ($state['lights'] ?? [] as $light) {
                if (hypot($point['x'] - $light['x'], $point['y'] - $light['y']) > ($light['radius'] ?? 0)) {
                    continue;
                }
                if (! self::lineBlocked($light, $point, $blockers)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function blockers(array $state): array
    {
        return [
            ...($state['walls'] ?? []),
            ...array_values(array_filter($state['doors'] ?? [], fn ($door) => ! ($door['open'] ?? false))),
        ];
    }

    private static function lineBlocked(array $from, array $to, array $blockers, ?string $ignoreDoorId = null): bool
    {
        foreach ($blockers as $wall) {
            if ($ignoreDoorId !== null && ($wall['id'] ?? null) === $ignoreDoorId) {
                continue;
            }
            if (SegmentGeometry::intersects($from, $to, $wall, false)) {
                return true;
            }
        }

        return false;
    }
}
