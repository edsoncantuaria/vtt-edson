<?php

namespace Tests\Unit;

use App\Support\Dnd\HouseRules;
use PHPUnit\Framework\TestCase;

class HouseRulesTest extends TestCase
{
    public function test_modifiers_fold_into_existing_bonus_and_match_accents(): void
    {
        $rule = ['name' => 'Nevoeiro', 'match' => 'percepcao', 'modifier' => -1, 'enabled' => true];
        $result = HouseRules::apply('1d20+4', 'Perícia: Percepção', [$rule]);
        $this->assertSame('1d20+3', $result['formula']);
        $this->assertSame(['Nevoeiro'], $result['rules']);
        $this->assertSame('1d20+4', HouseRules::apply('1d20+4', 'Atletismo', [$rule])['formula']);
        $this->assertSame('1d20+4', HouseRules::apply('1d20+4', 'Percepção', [])['formula']);
    }
}
