<?php

namespace Tests\Unit;

use App\Game\Dice\DiceRoller;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DiceRollerTest extends TestCase
{
    public function test_rolls_basic_formula(): void
    {
        $roller = new DiceRoller;
        $result = $roller->roll('2d6+3');

        $this->assertSame('2d6+3', $result['formula']);
        $this->assertCount(2, $result['rolls']);
        $this->assertSame(array_sum($result['rolls']) + 3, $result['total']);
    }

    public function test_rejects_invalid_formula(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DiceRoller)->roll('fireball');
    }
}
