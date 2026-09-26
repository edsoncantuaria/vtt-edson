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

    public function test_advantage_keeps_highest_of_two(): void
    {
        $roller = new DiceRoller;
        $result = $roller->roll('2d20kh1+5');

        $this->assertSame('2d20kh1+5', $result['formula']);
        $this->assertCount(2, $result['rolls']);
        $this->assertCount(1, $result['kept']);
        $this->assertSame(max($result['rolls']), $result['kept'][0]);
        $this->assertSame($result['kept'][0] + 5, $result['total']);
        $this->assertSame($result['kept'][0], $result['natural']);
    }

    public function test_disadvantage_keeps_lowest_of_two(): void
    {
        $result = (new DiceRoller)->roll('2d20kl1');

        $this->assertCount(1, $result['kept']);
        $this->assertSame(min($result['rolls']), $result['kept'][0]);
    }

    public function test_flags_natural_twenty_as_critical(): void
    {
        $roller = new DiceRoller(fn (int $min, int $max) => $max);
        $result = $roller->roll('d20');

        $this->assertSame(20, $result['natural']);
        $this->assertTrue($result['critical']);
        $this->assertFalse($result['fumble']);
    }

    public function test_deterministic_injected_dice_cover_kept_result_and_extra_die(): void
    {
        $sequence = [2, 19, 3];
        $roller = new DiceRoller(function () use (&$sequence) {
            return array_shift($sequence);
        });
        $roll = $roller->roll('2d20kh1+5+d4');
        $this->assertSame([2, 19, 3], $roll['rolls']);
        $this->assertSame(19, $roll['natural']);
        $this->assertSame(27, $roll['total']);
        $this->assertFalse($roll['critical']);
        $this->assertSame([], $sequence);
    }

    public function test_rejects_keep_count_larger_than_dice_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DiceRoller)->roll('2d20kh3');
    }
}
