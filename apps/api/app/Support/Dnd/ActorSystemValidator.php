<?php

namespace App\Support\Dnd;

use App\Models\CatalogEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ActorSystemValidator
{
    public function validate(Request $request): void
    {
        $request->validate([
            'system.actions' => ['sometimes', 'array', 'max:200'],
            'system.actions.*' => ['array'],
            'system.spells.slots' => ['sometimes', 'array'],
            'system.spells.slots.*' => ['array'],
            'system.spells.slots.*.max' => ['required', 'integer', 'between:0,100'],
            'system.spells.slots.*.used' => ['required', 'integer', 'between:0,100'],
            'system.hp.value' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'system.hp.max' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'system.hp.temp' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'system.ac' => ['sometimes', 'integer', 'between:0,100'],
            'system.abilities.*.score' => ['sometimes', 'integer', 'between:1,30'],
            'system.proficiencyBonus' => ['sometimes', 'integer', 'between:0,20'],
            'system.saves.*.bonus' => ['sometimes', 'integer', 'between:-30,50'],
            'system.saves.*.proficient' => ['sometimes', 'boolean'],
            'system.conditions' => ['sometimes', 'array', 'max:50'],
            'system.conditions.*' => ['string', 'max:120'],
            'system.concentration' => ['sometimes', 'nullable', 'array:id,name'],
            'system.concentration.id' => ['required_with:system.concentration', 'string', 'max:80'],
            'system.concentration.name' => ['required_with:system.concentration', 'string', 'max:120'],
            'system.damageTraits' => ['sometimes', 'array:resist,immune,vulnerable'],
            'system.damageTraits.*' => ['array', 'max:13'],
            'system.damageTraits.*.*' => [Rule::in(CombatRules::DAMAGE_TYPES)],
            'system.bio.level' => ['sometimes', 'integer', 'between:1,20'],
            'system.progression' => ['sometimes', 'nullable', 'array:classes,subclass,subclasses'],
            'system.progression.classes' => ['required_with:system.progression', 'array', 'min:1', 'max:20'],
            'system.progression.classes.*.classId' => ['sometimes', 'integer', 'min:1'],
            'system.progression.classes.*.name' => ['required', 'string', 'max:120'],
            'system.progression.classes.*.source' => ['sometimes', 'string', 'max:80'],
            'system.progression.classes.*.level' => ['required', 'integer', 'between:1,20'],
            'system.progression.classes.*.hitDie' => ['sometimes', 'integer', 'between:4,12'],
            'system.progression.subclass' => ['sometimes', 'nullable', 'array:subclassId,name,source,className,classSource'],
            'system.progression.subclass.subclassId' => ['sometimes', 'integer', 'min:1'],
            'system.progression.subclass.name' => ['required_with:system.progression.subclass', 'string', 'max:120'],
            'system.progression.subclass.source' => ['sometimes', 'string', 'max:80'],
            'system.progression.subclass.className' => ['required_with:system.progression.subclass', 'string', 'max:120'],
            'system.progression.subclass.classSource' => ['sometimes', 'string', 'max:80'],
            'system.progression.subclasses' => ['sometimes', 'array', 'max:20'],
            'system.progression.subclasses.*' => ['array:subclassId,name,source,className,classSource'],
            'system.progression.subclasses.*.subclassId' => ['sometimes', 'integer', 'min:1'],
            'system.progression.subclasses.*.name' => ['required', 'string', 'max:120'],
            'system.progression.subclasses.*.source' => ['sometimes', 'string', 'max:80'],
            'system.progression.subclasses.*.className' => ['required', 'string', 'max:120'],
            'system.progression.subclasses.*.classSource' => ['sometimes', 'string', 'max:80'],
        ]);

        $this->validateProgression($request);
        foreach ($request->input('system.actions', []) as $action) {
            abort_unless(is_array($action), 422, 'Ação inválida.');
            CombatRules::validateAction($action);
        }
    }

    private function validateProgression(Request $request): void
    {
        $progression = $request->input('system.progression');
        if (! is_array($progression) || ! isset($progression['classes'])) {
            return;
        }

        $total = array_sum(array_map(fn ($class) => (int) ($class['level'] ?? 0), $progression['classes']));
        abort_unless($total >= 1 && $total <= 20, 422, 'O total de níveis das classes deve ficar entre 1 e 20.');
        $bioLevel = $request->input('system.bio.level');
        if ($bioLevel !== null) {
            abort_unless((int) $bioLevel === $total, 422, 'O nível total deve corresponder à soma dos níveis das classes.');
        }

        $classIds = array_values(array_filter(array_map(fn ($class) => $class['classId'] ?? null, $progression['classes']), fn ($id) => $id !== null));
        abort_if(collect($classIds)->duplicates()->isNotEmpty(), 422, 'A progressão não pode repetir a mesma classe.');
        if ($classIds) {
            $classCount = CatalogEntry::query()->whereIn('id', $classIds)->where('kind', 'classes')->count();
            abort_unless($classCount === count($classIds), 422, 'Revise os vínculos de classe da progressão.');
        }

        $subclasses = collect($progression['subclasses'] ?? []);
        if (is_array($progression['subclass'] ?? null)) {
            $subclasses->push($progression['subclass']);
        }
        $subclasses = $subclasses->unique(fn ($subclass) => ($subclass['subclassId'] ?? '').'|'.($subclass['className'] ?? '').'|'.($subclass['classSource'] ?? ''))->values();
        $classKeys = [];
        foreach ($subclasses as $subclass) {
            $declaredClass = ($subclass['className'] ?? '').'|'.($subclass['classSource'] ?? '');
            abort_unless(! isset($classKeys[$declaredClass]), 422, 'Cada classe pode registrar somente uma subclasse.');
            $classKeys[$declaredClass] = true;
            if (! isset($subclass['subclassId'])) {
                continue;
            }
            $entry = CatalogEntry::query()->whereKey($subclass['subclassId'])->where('kind', 'subclasses')->first();
            abort_unless($entry !== null, 422, 'Revise o vínculo da subclasse.');
            $className = data_get($entry->data, 'raw.className');
            $classSource = data_get($entry->data, 'raw.classSource');
            abort_unless(collect($progression['classes'])->contains(fn ($class) => ($class['name'] ?? null) === $className
                && (! $classSource || ! isset($class['source']) || $class['source'] === $classSource)), 422, 'A subclasse não pertence a nenhuma classe da progressão.');
        }
    }
}
