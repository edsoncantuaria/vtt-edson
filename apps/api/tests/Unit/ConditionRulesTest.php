<?php

namespace Tests\Unit;

use App\Support\Dnd\ConditionRules;
use PHPUnit\Framework\TestCase;

class ConditionRulesTest extends TestCase
{
    public function test_poisoned_restrained_and_prone_apply_unconditional_effects_without_stacking_disadvantage(): void
    {
        $system = ['speed' => 30, 'conditions' => ['Envenenado', 'restrained', 'prone']];
        $this->assertSame(['poisoned', 'restrained', 'prone'], ConditionRules::names($system));
        $this->assertSame(0, ConditionRules::apply($system)['speed']);
        $this->assertSame('disadvantage', ConditionRules::mode($system, 'attack')['mode']);
        $this->assertSame('normal', ConditionRules::mode($system, 'attack', 'advantage')['mode']);
        $this->assertSame('disadvantage', ConditionRules::mode($system, 'skill')['mode']);
        $this->assertSame('disadvantage', ConditionRules::mode($system, 'ability')['mode']);
        $this->assertSame('disadvantage', ConditionRules::mode($system, 'save', 'normal', 'dex')['mode']);
        $this->assertSame('normal', ConditionRules::mode($system, 'save', 'normal', 'con')['mode']);
    }

    public function test_restrained_target_grants_advantage_and_prone_depends_on_distance(): void
    {
        $attacker = ['conditions' => []];
        $this->assertSame('advantage', ConditionRules::attackMode($attacker, ['conditions' => ['restrained']], 'normal')['mode']);
        $this->assertSame('advantage', ConditionRules::attackMode($attacker, ['conditions' => ['prone']], 'normal', 5)['mode']);
        $this->assertSame('disadvantage', ConditionRules::attackMode($attacker, ['conditions' => ['prone']], 'normal', 10)['mode']);
        $this->assertSame('normal', ConditionRules::attackMode(['conditions' => ['poisoned']], ['conditions' => ['restrained']], 'normal')['mode']);
        $this->assertSame('normal', ConditionRules::attackMode(['conditions' => ['poisoned']], ['conditions' => []], 'advantage')['mode']);
        $this->assertSame('advantage', ConditionRules::attackMode(['conditions' => ['invisible']], null, 'normal')['mode']);
    }

    public function test_incapacitated_blocks_actions_but_contextual_frightened_is_not_automatically_applied(): void
    {
        $this->assertFalse(ConditionRules::canAct(['conditions' => ['stunned']]));
        $this->assertFalse(ConditionRules::canAct(['conditions' => ['paralyzed']]));
        $this->assertTrue(ConditionRules::canAct(['conditions' => ['frightened']]));
        $this->assertSame('normal', ConditionRules::mode(['conditions' => ['frightened']], 'attack')['mode']);
    }
}
