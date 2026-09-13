<?php

namespace Tests\Unit;

use App\Support\Geometry\SegmentGeometry;
use PHPUnit\Framework\TestCase;

final class SegmentGeometryTest extends TestCase
{
    public function test_movement_rejects_crossing_and_destination_on_blocker(): void
    {
        $state = [
            'walls' => [['x1' => 10, 'y1' => 0, 'x2' => 10, 'y2' => 20]],
            'doors' => [],
        ];

        $this->assertTrue(SegmentGeometry::movementBlocked(['x' => 0, 'y' => 10], ['x' => 20, 'y' => 10], $state));
        $this->assertTrue(SegmentGeometry::movementBlocked(['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], $state));
        $this->assertFalse(SegmentGeometry::movementBlocked(['x' => 10, 'y' => 10], ['x' => 0, 'y' => 10], $state));
    }

    public function test_visibility_can_exclude_the_destination_intersection(): void
    {
        $wall = ['x1' => 10, 'y1' => 0, 'x2' => 10, 'y2' => 20];

        $this->assertFalse(SegmentGeometry::intersects(['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], $wall, false));
        $this->assertTrue(SegmentGeometry::intersects(['x' => 0, 'y' => 10], ['x' => 20, 'y' => 10], $wall, false));
    }

    public function test_closed_doors_block_movement_but_open_doors_do_not(): void
    {
        $baseDoor = ['x1' => 10, 'y1' => 0, 'x2' => 10, 'y2' => 20];
        $from = ['x' => 0, 'y' => 10];
        $to = ['x' => 20, 'y' => 10];

        $this->assertTrue(SegmentGeometry::movementBlocked($from, $to, ['walls' => [], 'doors' => [[...$baseDoor, 'open' => false]]]));
        $this->assertFalse(SegmentGeometry::movementBlocked($from, $to, ['walls' => [], 'doors' => [[...$baseDoor, 'open' => true]]]));
    }
}
