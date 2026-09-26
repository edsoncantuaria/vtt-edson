<?php

namespace Tests\Unit;

use App\Support\Dnd\ResourcePool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ResourcePoolTest extends TestCase
{
    private function system(): array
    {
        return ['inspiration' => true, 'spells' => ['slots' => [
            '1' => ['max' => 2, 'used' => 1], '2' => ['max' => 1, 'used' => 0, 'reset' => 'short'],
        ]], 'resources' => [
            ['id' => 'rage', 'name' => 'Fúria', 'kind' => 'rage', 'max' => 4, 'used' => 3, 'reset' => 'long', 'defaultCost' => 2],
            ['id' => 'focus', 'name' => 'Foco', 'kind' => 'focus', 'max' => 5, 'used' => 4, 'reset' => 'short'],
            ['id' => 'cd', 'name' => 'Canalizar', 'kind' => 'channel-divinity', 'max' => 3, 'used' => 2, 'reset' => 'long'],
            ['id' => 'brew', 'name' => 'Poção própria', 'max' => 3, 'used' => 2, 'reset' => 'manual',
                'recovery' => ['short' => 'none', 'long' => 'one']],
        ]];
    }

    public function test_projection_preserves_legacy_state_and_unifies_slots_inspiration_and_typed_resources(): void
    {
        $pools = ResourcePool::pools($this->system(), '5e-2024', 42);
        $this->assertSame(['slot:1', 'slot:2', 'inspiration', 'rage', 'focus', 'cd', 'brew'], array_column($pools, 'id'));
        $this->assertSame([1, 1, 1, 1, 1, 1, 1], array_column($pools, 'current'));
        $this->assertSame(['short' => 'one', 'long' => 'full'], $pools[3]['recovery']);
        $this->assertSame(2, $pools[3]['defaultCost']);
        $this->assertSame(42, $pools[3]['actorId']);
    }

    public function test_every_action_cost_is_quoted_before_consumption_and_custom_default_cost_is_respected(): void
    {
        $system = $this->system();
        $system['resources'][0]['used'] = 1;
        $action = ['spellSlotLevel' => 1, 'resourceId' => 'rage', 'documentId' => 17, 'chargeCost' => 2];
        $costs = ResourcePool::quote($system, $action, '5e-2024', ['max' => 3, 'value' => 2]);
        $this->assertSame(['slot:1', 'rage', 'document:17'], array_column($costs, 'id'));
        $this->assertSame([1, 2, 2], array_column($costs, 'cost'));
        $next = ResourcePool::spend($system, $costs);
        $this->assertSame(2, $next['spells']['slots']['1']['used']);
        $this->assertSame(3, $next['resources'][0]['used']);
        $this->assertSame($system['inspiration'], $next['inspiration']);
        $this->assertSame(1, $system['spells']['slots']['1']['used']);
        $inspiration = ResourcePool::quote($next, ['resourceId' => 'inspiration'], '5e-2024');
        $this->assertFalse(ResourcePool::spend($next, $inspiration)['inspiration']);
    }

    public function test_invalid_cost_or_edition_rejects_the_entire_quote_without_side_effects(): void
    {
        $system = $this->system();
        try {
            ResourcePool::quote($system, ['spellSlotLevel' => 1, 'resourceId' => 'rage', 'resourceCost' => 3], '5e-2024');
            $this->fail('Expected exhausted pool rejection.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Não há usos suficientes de Fúria.', $exception->getMessage());
        }
        $this->assertSame(1, $system['spells']['slots']['1']['used']);
        $system['resources'][0]['edition'] = '5e-2014';
        $this->expectException(InvalidArgumentException::class);
        ResourcePool::quote($system, ['resourceId' => 'rage'], '5e-2024');
    }

    public function test_short_long_and_manual_recovery_follow_policy_and_edition_without_replenishing_inspiration(): void
    {
        $before = $this->system();
        $short = ResourcePool::rest($before, 'short', '5e-2024');
        $this->assertSame(1, $short['spells']['slots']['1']['used']);
        $this->assertSame(0, $short['spells']['slots']['2']['used']);
        $this->assertSame(2, $short['resources'][0]['used']);
        $this->assertSame(0, $short['resources'][1]['used']);
        $this->assertSame(1, $short['resources'][2]['used']);
        $this->assertSame(2, $short['resources'][3]['used']);
        $this->assertTrue($short['inspiration']);
        $legacy = ResourcePool::rest($before, 'short', '5e-2014');
        $this->assertSame(3, $legacy['resources'][0]['used']);
        $this->assertSame(0, $legacy['resources'][2]['used']);
        $long = ResourcePool::rest($short, 'long', '5e-2024');
        $this->assertSame(0, $long['spells']['slots']['1']['used']);
        $this->assertSame(0, $long['resources'][0]['used']);
        $this->assertSame(1, $long['resources'][3]['used']);
        $this->assertSame(0, ResourcePool::restDocument(['max' => 3, 'value' => 0, 'reset' => 'long'], 'short')['value']);
        $this->assertSame(3, ResourcePool::restDocument(['max' => 3, 'value' => 0, 'reset' => 'long'], 'long')['value']);
        $this->assertSame(0, ResourcePool::restDocument(['max' => 3, 'value' => 0, 'reset' => 'dawn'], 'long')['value']);
    }

    public function test_adjust_limits_and_level_up_scaling_do_not_reset_spent_uses(): void
    {
        $system = $this->system();
        $this->assertSame(0, ResourcePool::adjust($system, 'slot:1', 2)['spells']['slots']['1']['used']);
        $this->assertFalse(ResourcePool::adjust($system, 'inspiration', 0)['inspiration']);
        $system['resources'][1]['scaling'] = ['className' => 'Monk', 'byLevel' => ['2' => 6]];
        $next = [...$system, 'progression' => ['classes' => [['name' => 'Monk', 'level' => 2]]]];
        $after = ResourcePool::advance($system, $next, '5e-2024');
        $this->assertSame(6, $after['resources'][1]['max']);
        $this->assertSame(4, $after['resources'][1]['used']);
        $this->assertSame(5, $system['resources'][1]['max']);
        $this->expectException(InvalidArgumentException::class);
        ResourcePool::adjust($system, 'focus', 100);
    }
}
