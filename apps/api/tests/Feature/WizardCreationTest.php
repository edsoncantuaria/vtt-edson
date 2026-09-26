<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CatalogEntry;
use App\Models\User;
use App\Support\Dnd\ActorStateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WizardCreationTest extends TestCase
{
    use RefreshDatabase;

    private function setupEdition(string $edition): array
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        Sanctum::actingAs($gm);
        $room = $this->postJson('/api/rooms', ['name' => 'Wizard', 'library' => 'all', 'ruleset' => $edition])->assertCreated()->json();
        $cid = $room['campaign']['id'];
        $class = CatalogEntry::create(['slug' => 'fighter-'.$edition, 'kind' => 'classes', 'name' => 'Fighter', 'source' => $edition === '5e-2024' ? 'XPHB' : 'PHB', 'edition' => $edition,
            'data' => ['raw' => ['startingProficiencies' => ['skills' => [['choose' => ['count' => 1, 'from' => ['athletics', 'acrobatics']]]]]]]]);
        $race = CatalogEntry::create(['slug' => 'elf-'.$edition, 'kind' => 'races', 'name' => 'Elf', 'source' => $class->source, 'edition' => $edition,
            'data' => ['raw' => ['languageProficiencies' => [['common' => true, 'choose' => ['count' => 1, 'from' => ['elvish', 'dwarvish']]]]]]]);
        $bg = CatalogEntry::create(['slug' => 'sage-'.$edition, 'kind' => 'backgrounds', 'name' => 'Sage', 'source' => $class->source, 'edition' => $edition,
            'data' => ['raw' => $edition === '5e-2024' ? ['feats' => [['any' => 1]]] : []]]);
        Sanctum::actingAs($player);
        $this->postJson('/api/rooms/join', ['code' => $room['room']['code']])->assertOk();

        return [$gm, $player, $cid, $class, $race, $bg];
    }

    private function payload(string $edition, CatalogEntry $class, CatalogEntry $race, CatalogEntry $bg): array
    {
        $system = ActorStateFactory::character();
        foreach (['str' => 15, 'dex' => 14, 'con' => 13, 'int' => 12, 'wis' => 10, 'cha' => 8] as $ability => $score) {
            $system['abilities'][$ability]['score'] = $score;
        }
        $system['skills']['athletics']['proficient'] = true;
        $system['languages'] = ['Comum', 'common', 'elvish'];
        $system['inventory'] = [['id' => 'starter', 'name' => 'Starter Pack', 'quantity' => 1, 'equipped' => false]];
        $system['bio'] = [...$system['bio'], 'class' => $class->name, 'race' => $race->name, 'background' => $bg->name];
        $system['progression'] = ['classes' => [['classId' => $class->id, 'name' => $class->name, 'source' => $class->source, 'level' => 1]], 'subclass' => null];
        $system['preparation'] = ['classId' => $class->id, 'source' => $class->source, 'edition' => $edition, 'tasks' => [], 'creation' => [
            'raceId' => $race->id, 'backgroundId' => $bg->id,
            'skills' => ['athletics'], 'languages' => ['elvish'], 'tools' => [], 'originSkills' => [],
            'scoreMethod' => 'standard', 'baseScores' => ['str' => 15, 'dex' => 14, 'con' => 13, 'int' => 12, 'wis' => 10, 'cha' => 8],
            'abilityOption' => 0, 'chosenAbilities' => [],
            'bonuses' => ['str' => 0, 'dex' => 0, 'con' => 0, 'int' => 0, 'wis' => 0, 'cha' => 0],
            'choiceSelections' => ['race-languageProficiencies' => ['elvish']],
            'manualEquipmentConfirmed' => true,
        ]];

        return ['type' => 'character', 'name' => 'Hero', 'system' => $system];
    }

    public function test_2014_creation_validates_subrace_skills_sources_and_provenance(): void
    {
        [, , $cid, $class, $race, $bg] = $this->setupEdition('5e-2014');
        $subrace = CatalogEntry::create(['slug' => 'high-elf', 'kind' => 'races', 'name' => 'High Elf', 'source' => 'PHB', 'edition' => '5e-2014',
            'data' => ['raw' => ['raceName' => 'Elf', 'raceSource' => 'PHB']]]);
        $this->getJson('/api/catalog/races?campaignId='.$cid.'&edition=5e-2014')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Elf');
        $this->getJson('/api/catalog/races?campaignId='.$cid.'&edition=5e-2014&raceName=Elf&raceSource=PHB')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'High Elf');
        $path = '/api/campaigns/'.$cid.'/actors';
        $payload = $this->payload('5e-2014', $class, $race, $bg);
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['preparation']['creation']['subraceId'] = $subrace->id;
        $payload['system']['bio']['race'] = 'Elf · High Elf';
        $this->postJson($path, $payload)->assertCreated()->assertJsonPath('actor.system.preparation.creation.subraceId', $subrace->id);
        $payload['system']['preparation']['creation']['skills'] = ['history'];
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['preparation']['creation']['skills'] = ['athletics'];
        $payload['system']['preparation']['creation']['languages'] = [];
        $this->postJson($path, $payload)->assertUnprocessable();
    }

    public function test_2024_requires_origin_feat_and_rejects_cross_edition_and_unsigned_exceptions(): void
    {
        [$gm, $player, $cid, $class, $race, $bg] = $this->setupEdition('5e-2024');
        $feat = CatalogEntry::create(['slug' => 'alert-xphb', 'kind' => 'feats', 'name' => 'Alert', 'source' => 'XPHB', 'edition' => '5e-2024', 'data' => []]);
        $path = '/api/campaigns/'.$cid.'/actors';
        $payload = $this->payload('5e-2024', $class, $race, $bg);
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['preparation']['creation']['featId'] = $feat->id;
        $payload['system']['features'][] = ['id' => 'alert', 'name' => 'Alert', 'source' => 'XPHB'];
        $this->postJson($path, $payload)->assertCreated()->assertJsonPath('actor.system.preparation.creation.featId', $feat->id);
        $payload['system']['preparation']['tasks'] = [['text' => 'Unrecognized option', 'done' => false]];
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['preparation']['tasks'][0]['done'] = true;
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['preparation']['tasks'][0]['done'] = false;
        $payload['system']['preparation']['creation']['exceptionReason'] = 'The GM explicitly reviewed the unsupported option.';
        $this->postJson($path, $payload)->assertForbidden();
        Sanctum::actingAs($gm);
        $this->postJson($path, $payload)->assertCreated()->assertJsonPath('actor.system.preparation.creation.exceptionReason', $payload['system']['preparation']['creation']['exceptionReason']);
        $foreign = CatalogEntry::create(['slug' => 'other-race', 'kind' => 'races', 'name' => 'Other', 'source' => 'PHB', 'edition' => '5e-2014', 'data' => []]);
        $payload['system']['preparation']['creation']['raceId'] = $foreign->id;
        $this->postJson($path, $payload)->assertUnprocessable();
        $this->assertSame('player', Campaign::findOrFail($cid)->roleFor($player));
    }

    public function test_edition_specific_ability_bonus_must_match_the_resulting_sheet(): void
    {
        [, , $cid, $class, $race, $bg] = $this->setupEdition('5e-2024');
        $data = $bg->data;
        $data['raw']['feats'] = [];
        $data['raw']['ability'] = [['choose' => ['weighted' => ['from' => ['str', 'dex', 'con'], 'weights' => [2, 1]]]]];
        $bg->update(['data' => $data]);
        $payload = $this->payload('5e-2024', $class, $race, $bg);
        $path = '/api/campaigns/'.$cid.'/actors';
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['preparation']['creation']['chosenAbilities'] = ['str', 'dex'];
        $payload['system']['preparation']['creation']['bonuses']['str'] = 2;
        $payload['system']['preparation']['creation']['bonuses']['dex'] = 1;
        $payload['system']['abilities']['str']['score'] = 17;
        $payload['system']['abilities']['dex']['score'] = 15;
        $this->postJson($path, $payload)->assertCreated()->assertJsonPath('actor.system.abilities.str.score', 17);
        $payload['system']['abilities']['dex']['score'] = 16;
        $this->postJson($path, $payload)->assertUnprocessable();
        $payload['system']['abilities']['dex']['score'] = 15;
        $payload['system']['preparation']['creation']['chosenAbilities'] = ['str', 'str'];
        $this->postJson($path, $payload)->assertUnprocessable();
    }
}
