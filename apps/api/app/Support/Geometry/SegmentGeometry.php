<?php

namespace App\Support\Geometry;

final class SegmentGeometry
{
    private const EPSILON = 0.000001;

    public static function movementBlocked(array $from, array $to, array $state): bool
    {
        if (hypot(($to['x'] ?? 0) - ($from['x'] ?? 0), ($to['y'] ?? 0) - ($from['y'] ?? 0)) < self::EPSILON) {
            return false;
        }

        $blockers = [
            ...($state['walls'] ?? []),
            ...array_values(array_filter($state['doors'] ?? [], fn (array $door): bool => ! ($door['open'] ?? false))),
        ];

        foreach ($blockers as $blocker) {
            if (self::intersects($from, $to, $blocker, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tests a ray segment against a wall segment. The starting point is always
     * excluded so a token/viewer may move/look away from a blocker it touches.
     */
    public static function intersects(array $from, array $to, array $wall, bool $includeDestination): bool
    {
        $rx = $to['x'] - $from['x'];
        $ry = $to['y'] - $from['y'];
        $sx = $wall['x2'] - $wall['x1'];
        $sy = $wall['y2'] - $wall['y1'];
        $qx = $wall['x1'] - $from['x'];
        $qy = $wall['y1'] - $from['y'];
        $divisor = $rx * $sy - $ry * $sx;

        if (abs($divisor) < self::EPSILON) {
            if (abs($qx * $ry - $qy * $rx) > self::EPSILON) {
                return false;
            }

            $length = $rx * $rx + $ry * $ry;
            if ($length < self::EPSILON) {
                return false;
            }

            $t1 = ($qx * $rx + $qy * $ry) / $length;
            $t2 = (($wall['x2'] - $from['x']) * $rx + ($wall['y2'] - $from['y']) * $ry) / $length;
            $upperBound = $includeDestination ? 1 : 1 - self::EPSILON;

            return max($t1, $t2) > self::EPSILON && min($t1, $t2) <= $upperBound;
        }

        $t = ($qx * $sy - $qy * $sx) / $divisor;
        $u = ($qx * $ry - $qy * $rx) / $divisor;
        $destinationMatches = $includeDestination ? $t <= 1 : $t < 1;

        return $t > self::EPSILON && $destinationMatches && $u >= 0 && $u <= 1;
    }
}
