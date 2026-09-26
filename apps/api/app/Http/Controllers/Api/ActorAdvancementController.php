<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Models\CatalogEntry;
use App\Support\Dnd\ActorDocumentService;
use App\Support\Dnd\ActorSystemValidator;
use App\Support\Dnd\ResourceAudit;
use App\Support\Dnd\ResourcePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Level-up is a previewable, version-checked domain write, not a free-form sheet overwrite. */
final class ActorAdvancementController extends Controller
{
    public function store(Request $request, Actor $actor, ActorSystemValidator $validator, ActorDocumentService $documents): JsonResponse
    {
        $data = $request->validate([
            'requestId' => ['required', 'uuid'],
            'revision' => ['required', 'integer', 'min:0'],
            'classId' => ['required', 'integer', 'min:1'],
            'targetLevel' => ['required', 'integer', 'between:2,20'],
            'subclassId' => ['nullable', 'integer', 'min:1'],
            'featId' => ['nullable', 'integer', 'min:1'],
            'asi' => ['nullable', 'array:str,dex,con,int,wis,cha'],
            'asi.*' => ['integer', 'between:1,2'],
            'overrideReason' => ['nullable', 'string', 'min:20', 'max:1000'],
            'preview' => ['sometimes', 'boolean'],
            'system' => ['required', 'array'],
        ]);
        $validator->validate($request);
        $hash = hash('sha256', json_encode(collect($data)->except(['preview', 'requestId'])->all(), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $actor, $data, $hash, $documents) {
            $actor = Actor::query()->lockForUpdate()->findOrFail($actor->id);
            $campaign = $actor->campaign;
            $role = $campaign->roleFor($request->user());
            abort_unless($role && $role !== 'observer' && ($campaign->canManage($request->user()) || $actor->isOwnedBy($request->user())
                || CampaignResourcePermission::permits($campaign, $request->user(), 'actor', $actor->id, 'edit')), 403);
            abort_unless($actor->type === 'character', 422, 'Evolução automática está disponível somente para personagens.');
            $record = DB::table('actor_advancements')->where(['actor_id' => $actor->id, 'request_id' => $data['requestId']])->first();
            if ($record && ! ($data['preview'] ?? false)) {
                abort_unless($record->request_hash === $hash && ! $record->undone, 409, 'A chave da evolução já foi utilizada com outros dados.');

                return response()->json(['actor' => $actor->toPayload(), 'alreadyApplied' => true, 'advancementId' => $record->id]);
            }
            abort_unless((int) $actor->revision === (int) $data['revision'], 409, 'A ficha foi alterada. Revise a prévia antes de aplicar a evolução.');
            $current = $actor->system;
            $next = $data['system'];
            $target = (int) $data['targetLevel'];
            abort_unless((int) ($current['bio']['level'] ?? 0) + 1 === $target
                && (int) data_get($next, 'bio.level') === $target, 422, 'A evolução deve acontecer exatamente um nível por vez.');
            $class = $this->entry($actor, (int) $data['classId'], 'classes');
            $this->verifyProgression($actor, $current, $next, $class, $data, $request);
            // The submitted snapshot must pass unchanged progression checks first;
            // explicit class resource tables are then applied identically in preview and commit.
            $next = ResourcePool::advance($current, $next, $campaign->ruleset);
            $beforePools = ResourceAudit::snapshot($actor, $current);
            $diff = [
                'level' => [(int) data_get($current, 'bio.level'), $target],
                'hp' => [(int) data_get($current, 'hp.max'), (int) data_get($next, 'hp.max')],
                'proficiency' => [(int) data_get($current, 'proficiencyBonus'), (int) data_get($next, 'proficiencyBonus')],
                'slots' => [data_get($current, 'spells.slots', []), data_get($next, 'spells.slots', [])],
                'resources' => [$current['resources'] ?? [], $next['resources'] ?? []],
            ];
            if ($data['preview'] ?? false) {
                return response()->json(['preview' => $diff, 'next' => $next, 'revision' => $actor->revision]);
            }
            $actor->system = $next;
            $actor->save();
            $documents->syncFromLegacy($actor);
            $documents->syncLegacy($actor);
            $fresh = $actor->fresh();
            ResourceAudit::record($fresh, 'level-up', $beforePools, ResourceAudit::snapshot($fresh), $request->user()->id,
                null, null, null, 'level:'.$target);
            $id = DB::table('actor_advancements')->insertGetId([
                'actor_id' => $actor->id, 'user_id' => $request->user()->id,
                'request_id' => $data['requestId'], 'request_hash' => $hash,
                'from_level' => $target - 1, 'to_level' => $target,
                'before_system' => json_encode($current, JSON_THROW_ON_ERROR),
                'after_system' => json_encode($fresh->system, JSON_THROW_ON_ERROR),
                'exception_reason' => $data['overrideReason'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return response()->json(['actor' => $fresh->toPayload(), 'alreadyApplied' => false, 'advancementId' => $id, 'diff' => $diff]);
        });
    }

    private function entry(Actor $actor, int $id, string $kind): CatalogEntry
    {
        $campaign = $actor->campaign;
        $entry = CatalogEntry::query()->where('active', true)->where('kind', $kind)
            ->where('edition', $campaign->ruleset)->find($id);
        abort_unless($entry && ($campaign->catalog_sources === null || in_array($entry->source, $campaign->catalog_sources, true)), 422,
            'A escolha não pertence à edição/fontes habilitadas para a campanha.');

        return $entry;
    }

    private function verifyProgression(Actor $actor, array $before, array $next, CatalogEntry $class, array $data, Request $request): void
    {
        $target = (int) $data['targetLevel'];
        $original = data_get($before, 'progression.classes', []);
        if (! $original) {
            $originId = data_get($before, 'preparation.classId');
            abort_unless($originId, 422, 'Vincule a classe inicial desta ficha antiga antes de evoluir.');
            $original = [['classId' => $originId, 'name' => data_get($before, 'bio.class'),
                'source' => data_get($before, 'preparation.source'), 'level' => data_get($before, 'bio.level')]];
        }
        $after = data_get($next, 'progression.classes', []);
        abort_unless(is_array($after) && count($after) >= count($original) && count($after) <= count($original) + 1
            && array_sum(array_column($after, 'level')) === $target, 422, 'A progressão precisa conservar as classes e aumentar somente uma delas.');
        $selectedBefore = collect($original)->first(fn ($row) => (int) ($row['classId'] ?? 0) === $class->id
            || ($row['name'] ?? null) === $class->name && ($row['source'] ?? null) === $class->source);
        foreach ($original as $row) {
            $matching = collect($after)->first(fn ($afterRow) => ($afterRow['name'] ?? null) === ($row['name'] ?? null)
                && ($afterRow['source'] ?? null) === ($row['source'] ?? null));
            abort_unless($matching && (int) $matching['level'] === (int) $row['level'] + ($selectedBefore === $row ? 1 : 0), 422,
                'Classes anteriores não podem perder ou ganhar níveis nesta evolução.');
        }
        $classLevel = $selectedBefore ? (int) $selectedBefore['level'] + 1 : 1;
        if (! $selectedBefore) {
            $new = collect($after)->first(fn ($row) => (int) ($row['classId'] ?? 0) === $class->id);
            abort_unless($new && (int) $new['level'] === 1 && count($after) === count($original) + 1, 422,
                'A nova classe deve começar no nível 1.');
            foreach ([$class, ...collect($original)->map(fn ($row) => $this->entry($actor, (int) ($row['classId'] ?? 0), 'classes'))->all()] as $requirementClass) {
                $this->multiclassPrerequisites($before, $requirementClass);
            }
        }
        abort_unless(data_get($next, 'bio.class') === collect($after)->map(fn ($row) => $row['name'].' '.$row['level'])->implode(' / '), 422,
            'A descrição da classe deve acompanhar a progressão.');
        abort_unless(data_get($next, 'preparation.creation') == data_get($before, 'preparation.creation')
            && data_get($next, 'preparation.edition') === data_get($before, 'preparation.edition'), 422,
            'A evolução não pode alterar escolhas de criação ou trocar a edição da ficha.');
        abort_unless((int) data_get($next, 'proficiencyBonus') === 2 + intdiv($target - 1, 4)
            && (int) data_get($next, 'hitDice.total') === (int) data_get($before, 'hitDice.total', max(1, (int) data_get($before, 'bio.level', 1))) + 1
            && (int) data_get($next, 'hitDice.used', 0) === (int) data_get($before, 'hitDice.used', 0), 422, 'Revise o bônus de proficiência e os dados de vida.');
        $asiFeature = collect(data_get($class->data, 'levelFeatures', []))->contains(fn ($feature) => (int) ($feature['level'] ?? 0) === $classLevel
            && preg_match('/ability score improvement|aumento no valor de habilidade/i', (string) ($feature['name'] ?? '')));
        $asi = $data['asi'] ?? [];
        $featId = $data['featId'] ?? null;
        abort_if($asi && $featId || ($asi || $featId) && ! $asiFeature, 422, 'Revise a escolha de melhoria de atributo/talento neste nível.');
        if ($asiFeature && ! $asi && ! $featId) {
            abort(422, 'Escolha um aumento de atributo ou talento antes de confirmar a evolução.');
        }
        abort_if($asi && array_sum($asi) !== 2, 422, 'Distribua +2 em um atributo ou +1 em dois atributos.');
        $abilities = ['str', 'dex', 'con', 'int', 'wis', 'cha'];
        foreach ($abilities as $key) {
            $prior = (int) data_get($before, 'abilities.'.$key.'.score');
            $expected = $prior + (int) ($asi[$key] ?? 0);
            abort_unless((int) data_get($next, 'abilities.'.$key.'.score') === $expected && ($expected <= 20 || ($prior > 20 && ! isset($asi[$key]))), 422,
                'Os atributos finais não correspondem à escolha de evolução.');
        }
        foreach (data_get($class->data, 'levelFeatures', []) as $feature) {
            if ((int) ($feature['level'] ?? 0) !== $classLevel || ! isset($feature['name'])) {
                continue;
            }
            abort_unless(collect($next['features'] ?? [])->contains(fn ($row) => ($row['name'] ?? null) === $feature['name']), 422,
                'Uma característica da classe não foi adicionada à ficha.');
        }
        if ($featId) {
            $feat = $this->entry($actor, (int) $featId, 'feats');
            abort_unless(collect($next['features'] ?? [])->contains(fn ($row) => ($row['name'] ?? null) === $feat->name), 422,
                'O talento escolhido precisa constar da ficha resultante.');
        }
        $subclassId = $data['subclassId'] ?? null;
        $previousSubclasses = collect(data_get($before, 'progression.subclasses', []));
        if (! $previousSubclasses->count() && data_get($before, 'progression.subclass')) {
            $previousSubclasses->push(data_get($before, 'progression.subclass'));
        }
        $hasPriorSubclass = $previousSubclasses->contains(fn ($row) => ($row['className'] ?? null) === $class->name
            && ($row['classSource'] ?? $class->source) === $class->source);
        $availableSubclasses = CatalogEntry::query()->where('kind', 'subclasses')->where('active', true)
            ->where('edition', $actor->campaign->ruleset)->where('data->raw->className', $class->name)->get();
        $requiresSubclass = $availableSubclasses->contains(function ($option) use ($classLevel, $class) {
            if (data_get($option->data, 'raw.classSource', $class->source) !== $class->source) {
                return false;
            }
            $levels = collect(data_get($option->data, 'raw.subclassFeatures', []))
                ->filter(fn ($reference) => is_string($reference))
                ->map(fn ($reference) => (int) last(explode('|', $reference)))
                ->filter(fn ($level) => $level > 0);

            return $levels->isNotEmpty() && $levels->min() <= $classLevel;
        });
        abort_if($requiresSubclass && ! $hasPriorSubclass && ! $subclassId, 422,
            'Escolha a subclasse exigida neste nível antes de confirmar.');
        if ($subclassId) {
            $subclass = $this->entry($actor, (int) $subclassId, 'subclasses');
            abort_unless(data_get($subclass->data, 'raw.className') === $class->name
                && data_get($subclass->data, 'raw.classSource', $class->source) === $class->source, 422,
                'Subclasse não pertence à classe escolhida.');
            abort_unless(collect(data_get($next, 'progression.subclasses', []))->contains(fn ($row) => (int) ($row['subclassId'] ?? 0) === $subclass->id), 422,
                'A subclasse selecionada deve constar na progressão.');
        }
        $conBefore = (int) floor(((int) data_get($before, 'abilities.con.score') - 10) / 2);
        $conAfter = (int) floor(((int) data_get($next, 'abilities.con.score') - 10) / 2);
        $die = (int) data_get($class->data, 'raw.hd.faces', 8);
        $gain = max(1, (int) floor($die / 2) + 1 + $conBefore);
        $delta = $gain + ($conAfter - $conBefore) * $target;
        $hpMax = (int) data_get($before, 'hp.max') + $delta;
        abort_unless((int) data_get($next, 'hp.max') === $hpMax
            && (int) data_get($next, 'hp.value') === min($hpMax, (int) data_get($before, 'hp.value') + $delta)
            && data_get($next, 'hp.temp') === data_get($before, 'hp.temp'), 422, 'A prévia apresenta PV divergentes das regras da evolução.');
        foreach (['inventory', 'currency', 'conditions', 'resources', 'concentration', 'deathSaves'] as $key) {
            abort_unless(($before[$key] ?? null) == ($next[$key] ?? null), 422,
                'A evolução não pode substituir '.$key.' nem perder estado anterior da ficha.');
        }
        foreach ($before['features'] ?? [] as $feature) {
            $same = collect($next['features'] ?? [])->firstWhere('id', $feature['id'] ?? null);
            abort_unless($same && $same == $feature, 422, 'Evoluir não pode apagar ou reescrever características existentes.');
        }
        $oldSpells = collect(data_get($before, 'spells.known', []));
        $newSpells = collect(data_get($next, 'spells.known', []));
        abort_if($oldSpells->filter(fn ($spell) => ! $newSpells->contains('id', $spell['id'] ?? null))->count() > 1, 422,
            'Não é possível substituir mais de uma magia conhecida na mesma evolução.');
        foreach ($oldSpells as $priorSpell) {
            $existingSpell = $newSpells->firstWhere('id', $priorSpell['id'] ?? null);
            if ($existingSpell) {
                $unchanged = $priorSpell;
                $updated = $existingSpell;
                unset($unchanged['prepared'], $updated['prepared']);
                abort_unless($unchanged == $updated, 422, 'Magias existentes só podem alterar seu preparo nesta etapa.');
            }
        }
        $maxSpellLevel = collect(data_get($next, 'spells.slots', []))->keys()->map(fn ($level) => (int) $level)->max() ?? 0;
        foreach ($newSpells->filter(fn ($spell) => ! $oldSpells->contains('id', $spell['id'] ?? null)) as $spell) {
            $entry = CatalogEntry::query()->where('kind', 'spells')->where('slug', $spell['slug'] ?? '')->first();
            abort_unless($entry && $entry->active && $entry->edition === $actor->campaign->ruleset
                && ($actor->campaign->catalog_sources === null || in_array($entry->source, $actor->campaign->catalog_sources, true))
                && in_array($class->source.':'.$class->name, data_get($entry->data, 'spellLists', []), true), 422,
                'Uma magia nova não pertence à lista/fonte permitida da classe.');
            abort_unless((int) ($spell['level'] ?? -1) === (int) ($entry->level ?? 0)
                && ((int) ($spell['level'] ?? 0) === 0 || (int) $spell['level'] <= $maxSpellLevel), 422,
                'Magia de nível acima dos espaços de conjuração disponíveis.');
        }
        foreach (['cantripProgression' => fn ($spell) => (int) ($spell['level'] ?? 0) === 0,
            'spellsKnownProgression' => fn ($spell) => (int) ($spell['level'] ?? 0) > 0,
            'preparedSpellsProgression' => fn ($spell) => (int) ($spell['level'] ?? 0) > 0 && ($spell['prepared'] ?? false)] as $key => $predicate) {
            $expected = data_get($class->data, 'raw.'.$key.'.'.($classLevel - 1));
            abort_if($expected !== null && $newSpells->filter($predicate)->count() !== (int) $expected, 422,
                'O número de magias/truques deste nível diverge da progressão da classe.');
        }
        $slots = data_get($class->data, 'raw.classTableGroups', []);
        $table = collect($slots)->first(fn ($group) => isset($group['rowsSpellProgression']));
        $row = $table['rowsSpellProgression'][$classLevel - 1] ?? null;
        if (count($after) === 1 && is_array($row)) {
            $expected = [];
            foreach ($row as $index => $max) {
                if ($max > 0) {
                    $key = (string) ($index + 1);
                    $expected[$key] = ['max' => $max, 'used' => min($max, (int) data_get($before, 'spells.slots.'.$key.'.used', 0))];
                }
            }
            abort_unless(data_get($next, 'spells.slots', []) == $expected, 422, 'A tabela de espaços da classe diverge do nível escolhido.');
        } elseif (count($after) > 1 && data_get($before, 'spells.slots', []) != data_get($next, 'spells.slots', [])) {
            abort(422, 'A combinação de espaços multiclasse exige revisão; não substitua os espaços existentes automaticamente.');
        }
        if (count($after) > 1 && data_get($before, 'spells.slots', []) && ! $selectedBefore && ! $actor->campaign->canManage($request->user())) {
            abort(422, 'Multiclasse conjuradora exige revisão explícita do mestre.');
        }
        $reason = trim((string) ($data['overrideReason'] ?? ''));
        abort_if($reason !== '' && ! $actor->campaign->canManage($request->user()), 403, 'Somente o mestre pode justificar exceções de evolução.');
    }

    private function multiclassPrerequisites(array $system, CatalogEntry $class): void
    {
        $requirements = data_get($class->data, 'raw.multiclassing.requirements', []);
        $abilities = ['str', 'dex', 'con', 'int', 'wis', 'cha'];
        foreach ($abilities as $ability) {
            if (isset($requirements[$ability])) {
                abort_unless((int) data_get($system, 'abilities.'.$ability.'.score') >= (int) $requirements[$ability], 422,
                    'Os pré-requisitos da multiclasse não foram atendidos.');
            }
        }
        foreach ($requirements['or'] ?? [] as $group) {
            abort_unless(collect($abilities)->contains(fn ($ability) => isset($group[$ability])
                && (int) data_get($system, 'abilities.'.$ability.'.score') >= (int) $group[$ability]), 422,
                'É necessário atender a uma das alternativas de atributo da multiclasse.');
        }
        abort_if(data_get($class->data, 'raw.multiclassing.requirementsSpecial'), 422,
            'Esta classe possui pré-requisito especial; revise a progressão manualmente antes de avançar.');
    }
}
