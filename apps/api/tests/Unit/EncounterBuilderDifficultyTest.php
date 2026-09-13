<?php

namespace Tests\Unit;

use App\Models\CatalogEntry;
use App\Support\EncounterBuilderDifficulty;
use PHPUnit\Framework\TestCase;

class EncounterBuilderDifficultyTest extends TestCase
{
    public function test_it_builds_creature_snapshot_and_rates_encounter(): void
    {
        $goblin = new CatalogEntry([
            'id' => 10, 'name' => 'Goblin', 'source' => 'MM', 'kind' => 'monsters',
            'data' => ['raw' => ['cr' => '1/4']],
        ]);
        $goblin->id = 10;

        $creature = EncounterBuilderDifficulty::creature($goblin, 4);
        $summary = EncounterBuilderDifficulty::summarize([$creature], array_fill(0, 4, ['level' => 1]));

        $this->assertSame(50, $creature['xp']);
        $this->assertSame(200, $summary['total']);
        $this->assertSame(400, $summary['adjusted']);
        $this->assertSame('deadly', $summary['rating']);
    }

    public function test_it_reports_unrated_without_party_levels(): void
    {
        $summary = EncounterBuilderDifficulty::summarize([
            ['xp' => 450, 'quantity' => 1],
        ], []);
        $this->assertSame('unrated', $summary['rating']);
        $this->assertSame(450, $summary['adjusted']);
    }

    public function test_2024_uses_per_character_budget_without_2014_monster_multiplier(): void
    {
        $summary = EncounterBuilderDifficulty::summarize([
            ['xp' => 50, 'quantity' => 4],
        ], array_fill(0, 4, ['level' => 1]), '5e-2024');

        $this->assertSame(200, $summary['total']);
        $this->assertSame(200, $summary['adjusted']);
        $this->assertSame(1, $summary['multiplier']);
        $this->assertSame(['low' => 200, 'moderate' => 300, 'high' => 400], $summary['thresholds']);
        $this->assertSame('low', $summary['rating']);
        $this->assertSame('5e-2024', $summary['ruleset']);
    }
}
