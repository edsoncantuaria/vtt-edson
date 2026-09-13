<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HouseRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_are_gm_only_and_scoped_to_the_campaign(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $a = $this->postJson('/api/rooms', ['name' => 'A'])->assertCreated()->json();
        $b = $this->postJson('/api/rooms', ['name' => 'B'])->assertCreated()->json();
        $url = '/api/campaigns/'.$a['campaign']['id'].'/house-rules';
        $rules = [['id' => 'fog', 'name' => 'Nevoeiro', 'match' => 'Percepção', 'modifier' => -1, 'enabled' => true]];
        $this->putJson($url, ['rules' => $rules])->assertOk();
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $a['room']['code']])->assertOk();
        $this->getJson($url)->assertOk();
        $this->putJson($url, ['rules' => []])->assertForbidden();
        $this->postJson('/api/scenes/'.$a['scene']['id'].'/chat', ['text' => '/roll 1d20+4', 'label' => 'Perícia: Percepção'])->assertOk()->assertJsonPath('message.formula', 'd20+3')->assertJsonPath('message.houseRules.0', 'Nevoeiro');
        Sanctum::actingAs($gm);
        $this->postJson('/api/scenes/'.$b['scene']['id'].'/chat', ['text' => '/roll 1d20+4', 'label' => 'Perícia: Percepção'])->assertOk()->assertJsonPath('message.formula', 'd20+4');
        $this->putJson($url, ['rules' => []])->assertOk();
    }
}
