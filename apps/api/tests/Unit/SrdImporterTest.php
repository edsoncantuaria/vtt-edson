<?php

namespace Tests\Unit;

use App\Support\Dnd\SrdImporter;
use PHPUnit\Framework\TestCase;

class SrdImporterTest extends TestCase
{
    public function test_maps_spell_payload(): void
    {
        $mapped = SrdImporter::mapSpell([
            'slug' => 'fireball',
            'name' => 'Fireball',
            'level' => '3rd-level',
            'level_int' => 3,
            'school' => 'Evocation',
            'desc' => '...',
        ]);

        $this->assertSame('fireball', $mapped['slug']);
        $this->assertSame('Fireball', $mapped['name']);
        $this->assertSame(3, $mapped['level']);
        $this->assertSame('evocation', $mapped['school']);
    }

    public function test_falls_back_to_slugified_name_when_slug_missing(): void
    {
        $mapped = SrdImporter::mapSpell(['name' => 'Melf\'s Acid Arrow', 'level_int' => 2]);

        $this->assertSame('melfs-acid-arrow', $mapped['slug']);
    }

    public function test_maps_item_with_given_type(): void
    {
        $mapped = SrdImporter::mapItem(['slug' => 'longsword', 'name' => 'Longsword'], 'weapon');

        $this->assertSame('weapon', $mapped['type']);
        $this->assertSame('longsword', $mapped['slug']);
    }

    public function test_maps_monster_payload(): void
    {
        $mapped = SrdImporter::mapMonster([
            'slug' => 'goblin',
            'name' => 'Goblin',
            'challenge_rating' => '1/4',
        ]);

        $this->assertSame('1/4', $mapped['challenge_rating']);
    }
}
