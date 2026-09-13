<?php

namespace Tests\Unit;

use App\Game\Dice\DiceRoller;
use App\Models\RollTable;
use App\Support\RollTableExecutor;
use PHPUnit\Framework\TestCase;

class RollTableExecutorTest extends TestCase
{
    public function test_it_selects_the_matching_persistent_entry_shape(): void
    {
        $table = new RollTable([
            'name' => 'Coin', 'formula' => '1d2', 'enabled' => true,
            'entries' => [
                ['min' => 1, 'max' => 1, 'label' => 'Heads', 'result' => ['gp' => 1]],
                ['min' => 2, 'max' => 2, 'label' => 'Tails', 'result' => ['gp' => 2]],
            ],
        ]);
        $result = (new RollTableExecutor(new DiceRoller))->execute($table);
        $this->assertContains($result['entry']['label'], ['Heads', 'Tails']);
        $this->assertContains($result['roll']['total'], [1, 2]);
    }
}
