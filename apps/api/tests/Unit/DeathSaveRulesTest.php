<?php

namespace Tests\Unit;

use App\Support\Dnd\ActorStateFactory;
use App\Support\Dnd\DeathSaveRules;
use PHPUnit\Framework\TestCase;

class DeathSaveRulesTest extends TestCase
{
    public function test_natural_one_counts_twice_and_caps_failure_at_three(): void
    {
        $state = ActorStateFactory::character();
        $state['hp']['value'] = 0;
        $state['deathSaves'] = ['success' => 1, 'failure' => 2];
        $resolved = DeathSaveRules::resolve($state, 1);
        $this->assertSame(['success' => 1, 'failure' => 3], $resolved['system']['deathSaves']);
        $this->assertContains('morto', $resolved['system']['conditions']);
        $this->assertSame(2, $state['deathSaves']['failure']);
    }

    public function test_three_successes_stabilize_without_restoring_hit_points(): void
    {
        $state = ActorStateFactory::character();
        $state['hp']['value'] = 0;
        $state['deathSaves']['success'] = 2;
        $resolved = DeathSaveRules::resolve($state, 10);
        $this->assertSame(3, $resolved['system']['deathSaves']['success']);
        $this->assertSame(0, $resolved['system']['hp']['value']);
        $this->assertContains('estabilizado', $resolved['system']['conditions']);
    }

    public function test_natural_twenty_restores_one_hp_and_clears_markers(): void
    {
        $state = ActorStateFactory::character();
        $state['hp']['value'] = 0;
        $state['deathSaves'] = ['success' => 2, 'failure' => 2];
        $resolved = DeathSaveRules::resolve($state, 20);
        $this->assertSame(1, $resolved['system']['hp']['value']);
        $this->assertSame(['success' => 0, 'failure' => 0], $resolved['system']['deathSaves']);
    }
}
