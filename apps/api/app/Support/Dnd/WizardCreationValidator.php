<?php

namespace App\Support\Dnd;

use App\Models\Campaign;
use App\Models\CatalogEntry;
use Illuminate\Http\Request;

/** Checks a wizard submission against campaign-scoped source records, not just a browser draft. */
final class WizardCreationValidator
{
    public function validate(Request $request, Campaign $campaign, array $system): void
    {
        $creation = data_get($system, 'preparation.creation');
        if (! is_array($creation)) {
            return; // Legacy/manual actor import has no wizard contract.
        }
        $request->validate([
            'system.preparation.creation.raceId' => ['required', 'integer', 'min:1'],
            'system.preparation.creation.backgroundId' => ['required', 'integer', 'min:1'],
            'system.preparation.creation.subraceId' => ['nullable', 'integer', 'min:1'],
            'system.preparation.creation.featId' => ['nullable', 'integer', 'min:1'],
            'system.preparation.creation.skills' => ['present', 'array', 'max:20'],
            'system.preparation.creation.skills.*' => ['string', 'distinct', 'max:80'],
            'system.preparation.creation.languages' => ['present', 'array', 'max:30'],
            'system.preparation.creation.languages.*' => ['string', 'distinct', 'max:80'],
            'system.preparation.creation.tools' => ['present', 'array', 'max:30'],
            'system.preparation.creation.tools.*' => ['string', 'distinct', 'max:80'],
            'system.preparation.creation.originSkills' => ['present', 'array', 'max:30'],
            'system.preparation.creation.originSkills.*' => ['string', 'distinct', 'max:80'],
            'system.preparation.creation.scoreMethod' => ['required', 'in:standard,points'],
            'system.preparation.creation.baseScores' => ['required', 'array:str,dex,con,int,wis,cha'],
            'system.preparation.creation.baseScores.*' => ['required', 'integer', 'between:8,15'],
            'system.preparation.creation.abilityOption' => ['required', 'integer', 'min:0', 'max:10'],
            'system.preparation.creation.chosenAbilities' => ['present', 'array', 'max:6'],
            'system.preparation.creation.chosenAbilities.*' => ['in:str,dex,con,int,wis,cha', 'distinct'],
            'system.preparation.creation.bonuses' => ['required', 'array:str,dex,con,int,wis,cha'],
            'system.preparation.creation.bonuses.*' => ['required', 'integer', 'between:-5,10'],
            'system.preparation.creation.choiceSelections' => ['present', 'array', 'max:6'],
            'system.preparation.creation.manualEquipmentConfirmed' => ['required', 'boolean'],
            'system.preparation.creation.exceptionReason' => ['sometimes', 'string', 'min:20', 'max:1000'],
        ]);
        $class = $this->entry($campaign, (int) data_get($system, 'preparation.classId'), 'classes');
        $race = $this->entry($campaign, (int) $creation['raceId'], 'races');
        $background = $this->entry($campaign, (int) $creation['backgroundId'], 'backgrounds');
        abort_unless(data_get($race->data, 'raw.raceName') === null, 422, 'Selecione primeiro a espécie/raça base.');
        abort_unless(data_get($system, 'preparation.source') === $class->source
            && data_get($system, 'preparation.edition') === $campaign->ruleset
            && data_get($system, 'bio.class') === $class->name
            && data_get($system, 'bio.background') === $background->name
            && data_get($system, 'progression.classes.0.classId') === $class->id
            && (int) data_get($system, 'bio.level') === 1, 422, 'A prévia e as origens da criação não correspondem ao catálogo escolhido.');

        $exception = trim((string) ($creation['exceptionReason'] ?? ''));
        abort_if($exception !== '' && ! $campaign->canManage($request->user()), 403, 'Somente o mestre pode autorizar exceções na criação.');
        $pendingTasks = data_get($system, 'preparation.tasks', []);
        abort_if(collect($pendingTasks)->contains(fn ($task) => ($task['done'] ?? false) === true), 422,
            'Uma pendência do assistente não pode ser marcada como concluída sem resolver a escolha na criação.');
        $needsReview = count($pendingTasks) > 0;
        abort_if($needsReview && $exception === '', 422, 'Resolva as escolhas pendentes antes de criar a ficha ou peça revisão explícita do mestre.');

        $scores = $creation['baseScores'];
        if ($creation['scoreMethod'] === 'standard') {
            $sorted = array_values($scores);
            rsort($sorted);
            abort_unless($sorted === [15, 14, 13, 12, 10, 8], 422, 'Distribua cada valor padrão uma vez.');
        } else {
            $cost = [8 => 0, 9 => 1, 10 => 2, 11 => 3, 12 => 4, 13 => 5, 14 => 7, 15 => 9];
            abort_unless(array_sum(array_map(fn ($score) => $cost[$score], $scores)) <= 27, 422, 'A compra de atributos excede 27 pontos.');
        }
        $manualEquipment = collect([$class, $background])->contains(fn ($entry) => ! data_get($entry->data, 'raw.startingEquipment'));
        abort_if($manualEquipment && ($creation['manualEquipmentConfirmed'] !== true || empty($system['inventory'])) && $exception === '', 422,
            'Confirme e selecione o equipamento inicial manualmente antes de criar a ficha.');

        $knownSpells = collect(data_get($system, 'spells.known', []));
        $cantrips = data_get($class->data, 'raw.cantripProgression.0', 0);
        abort_if($knownSpells->where('level', 0)->count() !== (int) $cantrips && $exception === '', 422,
            'Selecione a quantidade de truques exigida pela classe.');
        $knownLimit = data_get($class->data, 'raw.spellsKnownProgression.0');
        if ($class->name === 'Wizard' && in_array($class->source, ['PHB', 'XPHB'], true)) {
            $knownLimit = 6;
        }
        if ($knownLimit !== null) {
            abort_if($knownSpells->where('level', '>', 0)->count() !== (int) $knownLimit && $exception === '', 422,
                'Revise o número de magias conhecidas/no grimório desta classe.');
        }
        foreach ($knownSpells as $spell) {
            $entry = CatalogEntry::query()->where('kind', 'spells')->where('slug', $spell['slug'] ?? '')->first();
            abort_unless($entry && $entry->active && $entry->edition === $campaign->ruleset
                && ($campaign->catalog_sources === null || in_array($entry->source, $campaign->catalog_sources, true)), 422,
                'Uma magia escolhida não está disponível para a edição/fonte desta campanha.');
            abort_unless(in_array($class->source.':'.$class->name, data_get($entry->data, 'spellLists', []), true) || $exception !== '', 422,
                'A magia selecionada não pertence à lista da classe.');
        }

        $choice = collect(data_get($class->data, 'raw.startingProficiencies.skills', []))
            ->first(fn ($row) => isset($row['choose']));
        if ($choice) {
            $allowed = $choice['choose']['from'] ?? [];
            $skills = $creation['skills'];
            abort_unless(count($skills) === (int) ($choice['choose']['count'] ?? 0)
                && count(array_diff($skills, $allowed)) === 0, 422, 'A escolha de perícias não corresponde à classe.');
            foreach ($skills as $skill) {
                $name = strtolower(str_replace(' ', '', $skill));
                abort_unless(data_get($system, 'skills.'.$name.'.proficient') === true, 422,
                    'As perícias selecionadas não estão aplicadas na ficha.');
            }
        }
        $subraces = CatalogEntry::query()->where('kind', 'races')->where('active', true)
            ->where('edition', $campaign->ruleset)->where('data->raw->raceName', $race->name)
            ->where(fn ($query) => $query->where('data->raw->raceSource', $race->source)->orWhereNull('data->raw->raceSource'));
        if ($campaign->ruleset === '5e-2014' && $subraces->exists() && empty($creation['subraceId']) && $exception === '') {
            abort(422, 'Escolha a sub-raça disponível antes de finalizar.');
        }
        if (! empty($creation['subraceId'])) {
            $subrace = $this->entry($campaign, (int) $creation['subraceId'], 'races');
            abort_unless(data_get($subrace->data, 'raw.raceName') === $race->name, 422, 'A sub-raça não pertence à raça escolhida.');
            abort_unless(data_get($system, 'bio.race') === $race->name.' · '.$subrace->name, 422, 'A sub-raça selecionada não consta na ficha resultante.');
        } else {
            $subrace = null;
        }
        if (! empty($creation['featId'])) {
            $feat = $this->entry($campaign, (int) $creation['featId'], 'feats');
            abort_unless(collect($system['features'] ?? [])->contains(fn ($feature) => ($feature['name'] ?? null) === $feat->name), 422,
                'O talento selecionado não foi incluído na ficha.');
        }
        if ($campaign->ruleset === '5e-2024' && collect(data_get($background->data, 'raw.feats', []))
            ->contains(fn ($feat) => isset($feat['any']) || isset($feat['choose']))
            && empty($creation['featId']) && $exception === '') {
            abort(422, 'Escolha o talento de origem antes de finalizar.');
        }
        $this->validateAbilities($creation, $system, $campaign, $race, $background, $subrace);
        foreach (['languages' => 'languageProficiencies', 'tools' => 'toolProficiencies', 'originSkills' => 'skillProficiencies'] as $field => $rawField) {
            $selected = $creation[$field];
            foreach (['race' => $race, 'subrace' => $subrace, 'background' => $background] as $source => $entry) {
                if (! $entry) {
                    continue;
                }
                $record = data_get($entry->data, 'raw.'.$rawField.'.0', []);
                $choose = $record['choose'] ?? null;
                $perSource = $creation['choiceSelections'][$source.'-'.$rawField] ?? [];
                if (is_array($choose)) {
                    $from = is_array($choose['from'] ?? null) ? $choose['from'] : [];
                    $count = (int) ($choose['count'] ?? 1);
                    if (($count < 1 || count($perSource) !== $count || count(array_unique($perSource)) !== $count
                        || count(array_diff($perSource, $from)) > 0) && $exception === '') {
                        abort(422, 'Revise as escolhas obrigatórias de '.$field.' da origem.');
                    }
                }
                abort_unless(count(array_diff($perSource, $selected)) === 0, 422, 'As escolhas de '.$field.' não correspondem à ficha.');
                foreach ($perSource as $value) {
                    if ($field === 'originSkills') {
                        abort_unless(data_get($system, 'skills.'.strtolower(str_replace(' ', '', $value)).'.proficient') === true, 422,
                            'A perícia de origem selecionada não foi aplicada na ficha.');
                    } else {
                        $list = $field === 'languages' ? ($system['languages'] ?? []) : explode(', ', (string) ($system['proficiencies'] ?? ''));
                        abort_unless(in_array($value, $list, true), 422, 'A escolha de '.$field.' não consta na ficha.');
                    }
                }
                foreach ($record as $value => $enabled) {
                    if ($enabled === true && $value !== 'choose') {
                        if ($field === 'originSkills') {
                            abort_unless(data_get($system, 'skills.'.strtolower(str_replace(' ', '', (string) $value)).'.proficient') === true,
                                422, 'A ficha omitiu uma perícia fixa da origem.');

                            continue;
                        }
                        $list = $field === 'languages' ? ($system['languages'] ?? []) : explode(', ', (string) ($system['proficiencies'] ?? ''));
                        abort_unless(in_array($value, $list, true), 422, 'A ficha omitiu uma proficiência fixa de '.$field.'.');
                    }
                }
                if (collect($record)->keys()->contains(fn ($key) => str_starts_with((string) $key, 'any')) && $exception === '') {
                    abort(422, 'A escolha aberta de '.$field.' precisa de revisão do mestre.');
                }
            }
        }
    }

    private function validateAbilities(array $creation, array $system, Campaign $campaign, CatalogEntry $race, CatalogEntry $background, ?CatalogEntry $subrace): void
    {
        $abilities = ['str', 'dex', 'con', 'int', 'wis', 'cha'];
        $origin = $campaign->ruleset === '5e-2024' ? $background : $race;
        $options = data_get($origin->data, 'raw.ability', []);
        $option = $options[$creation['abilityOption']] ?? [];
        abort_if($options && ! isset($options[$creation['abilityOption']]), 422, 'Opção de bônus da origem inexistente.');
        $expected = array_fill_keys($abilities, 0);
        foreach ($abilities as $ability) {
            $expected[$ability] += (int) ($option[$ability] ?? 0);
            $expected[$ability] += (int) data_get($subrace?->data, 'raw.ability.0.'.$ability, 0);
        }
        $choice = $option['choose'] ?? null;
        $selected = $creation['chosenAbilities'];
        if (is_array($choice)) {
            $weighted = $choice['weighted'] ?? null;
            $weights = is_array($weighted) ? ($weighted['weights'] ?? []) : array_fill(0, (int) ($choice['count'] ?? 1), (int) ($choice['amount'] ?? 1));
            $allowed = $weighted['from'] ?? $choice['from'] ?? $abilities;
            abort_unless(count($selected) === count($weights) && count(array_unique($selected)) === count($weights)
                && count(array_diff($selected, $allowed)) === 0, 422, 'Os bônus de origem não respeitam as escolhas permitidas.');
            foreach ($selected as $index => $ability) {
                $expected[$ability] += (int) $weights[$index];
            }
        } else {
            abort_if($selected !== [], 422, 'A origem não oferece escolha extra de bônus.');
        }
        foreach ($abilities as $ability) {
            abort_unless((int) ($creation['bonuses'][$ability] ?? 99) === $expected[$ability]
                && (int) data_get($system, 'abilities.'.$ability.'.score') === $creation['baseScores'][$ability] + $expected[$ability], 422,
                'Os atributos resultantes não correspondem aos valores e bônus selecionados.');
        }
    }

    private function entry(Campaign $campaign, int $id, string $kind): CatalogEntry
    {
        $entry = CatalogEntry::query()->where('active', true)->where('kind', $kind)
            ->where('edition', $campaign->ruleset)->find($id);
        abort_unless($entry && ($campaign->catalog_sources === null || in_array($entry->source, $campaign->catalog_sources, true)), 422,
            'A escolha da ficha não está disponível para esta edição/fonte da campanha.');

        return $entry;
    }
}
