<?php

namespace Tests\Unit;

use App\Game\Dice\DiceRoller;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_dice_roller_reports_a_bounded_d20_result(): void
    {
        $result = (new DiceRoller)->roll('d20');

        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertLessThanOrEqual(20, $result['total']);
    }
}
