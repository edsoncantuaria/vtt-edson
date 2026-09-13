<?php

namespace Tests\Unit;

use App\Game\Dice\DiceRoller;
use App\Support\Dnd\CombatRules;
use PHPUnit\Framework\TestCase;

class CombatRulesTest extends TestCase
{
    private function message(int $damage): array
    {
        return ['rolls' => [['kind' => 'damage', 'total' => $damage]], 'damageType' => 'fire', 'save' => ['effect' => 'half']];
    }

    public function test_save_resistance_and_vulnerability_round_in_order_without_stacking(): void
    {
        $result = CombatRules::damage($this->message(23), ['damageTraits' => ['resist' => ['fire', 'fire'], 'vulnerable' => ['fire']]], ['success' => true]);
        $this->assertSame(10, $result['damage']); // 23 / 2 = 11; / 2 = 5; ×2 = 10.
        $this->assertFalse($result['pendingSave']);
        $this->assertSame(0, CombatRules::damage($this->message(23), ['damageTraits' => ['immune' => ['fire']]], ['success' => false])['damage']);
    }

    public function test_manual_factor_replaces_all_multipliers_and_negative_damage_is_zero(): void
    {
        $this->assertSame(11, CombatRules::damage($this->message(23), ['damageTraits' => ['immune' => ['fire']]], null, .5)['damage']);
        $this->assertSame(0, CombatRules::damage($this->message(-4), [], null, 2)['damage']);
        $this->assertTrue(CombatRules::damage($this->message(23), [], null)['pendingSave']);
    }

    public function test_critical_doubles_dice_not_modifier_and_concentration_respects_edition(): void
    {
        $this->assertSame('4d6+3', CombatRules::criticalFormula('2d6+3'));
        $this->assertSame('2d8-2', CombatRules::criticalFormula('d8-2'));
        $this->assertSame(10, CombatRules::concentrationDc(19, '5e-2014'));
        $this->assertSame(40, CombatRules::concentrationDc(81, '5e-2014'));
        $this->assertSame(30, CombatRules::concentrationDc(81, '5e-2024'));
    }

    public function test_attack_natural_overrides_ac_but_save_natural_does_not_override_dc(): void
    {
        $message = ['rolls' => [['kind' => 'attack', 'total' => 100, 'fumble' => true], ['kind' => 'damage', 'total' => 10]]];
        $this->assertSame(0, CombatRules::damage($message, ['ac' => 5], null)['damage']);
        $message['rolls'][0] = ['kind' => 'attack', 'total' => 20, 'critical' => true];
        $this->assertSame(10, CombatRules::damage($message, ['ac' => 99], null)['damage']);
        // A guaranteed total above/below the DC proves no automatic success/failure.
        $low = CombatRules::save(['saves' => ['con' => ['bonus' => -30]]], 'con', 1, [], new DiceRoller, [], 'Save');
        $high = CombatRules::save(['saves' => ['con' => ['bonus' => 50]]], 'con', 30, [], new DiceRoller, [], 'Save');
        $this->assertFalse($low['success']);
        $this->assertTrue($high['success']);
    }

    public function test_attack_resolution_exposes_ac_and_miss_does_not_block_on_save(): void
    {
        $message = [
            'rolls' => [
                ['kind' => 'attack', 'total' => 14, 'critical' => false, 'fumble' => false],
                ['kind' => 'damage', 'total' => 12],
            ],
            'save' => ['effect' => 'half'],
        ];

        $result = CombatRules::damage($message, ['ac' => 15], null);

        $this->assertSame(['ac' => 15, 'total' => 14, 'critical' => false, 'fumble' => false, 'hit' => false], $result['attack']);
        $this->assertFalse($result['hit']);
        $this->assertFalse($result['pendingSave']);
        $this->assertSame(0, $result['damage']);
    }
}
