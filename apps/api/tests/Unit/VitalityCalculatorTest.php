<?php

namespace Tests\Unit;

use App\Support\Dnd\VitalityCalculator;
use PHPUnit\Framework\TestCase;

class VitalityCalculatorTest extends TestCase
{
    public function test_mixed_damage_applies_each_type_once_then_flat_reduction_once_with_traced_sources(): void
    {
        $parts = [
            ['id' => 'fire-roll', 'total' => 11, 'damageType' => 'fire'],
            ['id' => 'cold-roll', 'total' => 9, 'damageType' => 'cold'],
            ['id' => 'slash-roll', 'total' => 5, 'damageType' => 'slashing'],
        ];
        $system = ['damageTraits' => ['resist' => ['fire', 'fire'], 'vulnerable' => ['fire'], 'immune' => ['cold']], 'damageReduction' => 3];
        $result = VitalityCalculator::damage($parts, $system, null, null, null);
        $this->assertSame(25, $result['rolledDamage']);
        $this->assertSame([10, 0, 5], array_column($result['components'], 'calculated'));
        $this->assertSame(12, $result['automaticDamage']);
        $this->assertSame(12, $result['damage']);
        $this->assertSame('fire-roll', $result['components'][0]['rollId']);
        $this->assertContains('Ficha do alvo: redução fixa de dano (regra opcional explícita da mesa)', $result['sources']);
        $this->assertSame(1, count(array_filter($result['sources'], fn ($source) => $source === 'Ficha do alvo: resistência a fire')));
    }

    public function test_save_halves_each_typed_component_before_defenses_and_manual_amount_preserves_trace(): void
    {
        $parts = [['total' => 11, 'damageType' => 'fire'], ['total' => 5, 'damageType' => 'slashing']];
        $system = ['damageTraits' => ['resist' => ['fire'], 'vulnerable' => ['fire']], 'damageReduction' => 3];
        $outcome = VitalityCalculator::damage($parts, $system, ['success' => true], 'half', null);
        $this->assertSame([4, 2], array_column($outcome['components'], 'calculated'));
        $this->assertSame(3, $outcome['damage']);
        $this->assertSame(0, VitalityCalculator::damage($parts, $system, ['success' => true], 'none', null)['damage']);
        $manual = VitalityCalculator::damage($parts, $system, ['success' => true], 'none', null, null, 8);
        $this->assertSame(8, $manual['damage']);
        $this->assertSame(0, $manual['automaticDamage']);
        $this->assertContains('Decisão auditada do mestre: dano exato', $manual['sources']);
        $this->assertSame(8, VitalityCalculator::damage($parts, $system, null, null, null, .5)['damage']);
    }

    public function test_temporary_hit_points_are_consumed_first_and_hp_never_falls_below_zero(): void
    {
        $before = ['value' => 8, 'max' => 10, 'temp' => 4];
        $absorb = VitalityCalculator::applyDamage($before, 3);
        $this->assertSame(['value' => 8, 'max' => 10, 'temp' => 1], $absorb['after']);
        $this->assertSame(3, $absorb['absorbedTemp']);
        $lethal = VitalityCalculator::applyDamage($before, 30);
        $this->assertSame(['value' => 0, 'max' => 10, 'temp' => 0], $lethal['after']);
        $this->assertSame(4, $lethal['absorbedTemp']);
        $this->assertSame(8, $lethal['lostHp']);
        $this->assertSame($before, $before);
    }

    public function test_healing_caps_at_max_and_never_changes_temporary_hp_even_from_zero(): void
    {
        $before = ['value' => 0, 'max' => 10, 'temp' => 5];
        $result = VitalityCalculator::heal($before, 30);
        $this->assertSame(['value' => 10, 'max' => 10, 'temp' => 5], $result['after']);
        $this->assertSame(10, $result['restored']);
        $this->assertSame(20, $result['excess']);
        $this->assertSame(0, VitalityCalculator::heal($result['after'], 20)['restored']);
        $override = VitalityCalculator::heal($before, 30, 3);
        $this->assertSame(3, $override['restored']);
        $this->assertContains('Decisão auditada do mestre: cura exata', $override['sources']);
        $this->assertSame($before, $before);
    }
}
