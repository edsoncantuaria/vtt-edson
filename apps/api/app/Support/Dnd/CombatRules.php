<?php

namespace App\Support\Dnd;

use App\Game\Dice\DiceRoller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Explicit 5e mechanics only; conditional traits remain a table decision. */
final class CombatRules
{
    public const ABILITIES = ['str', 'dex', 'con', 'int', 'wis', 'cha'];

    public const DAMAGE_TYPES = ['acid', 'bludgeoning', 'cold', 'fire', 'force', 'lightning', 'necrotic', 'piercing', 'poison', 'psychic', 'radiant', 'slashing', 'thunder'];

    public static function validateAction(array $action): array
    {
        $validated = Validator::make($action, [
            'id' => ['sometimes', 'string', 'min:1', 'max:80'],
            'kind' => ['sometimes', Rule::in(['attack', 'spell', 'feature', 'item'])],
            'name' => ['sometimes', 'string', 'min:1', 'max:120'],
            'origin' => ['sometimes', 'string', 'max:160'],
            'visibility' => ['sometimes', Rule::in(['public', 'gm'])],
            'attackFormula' => ['sometimes', 'string', 'max:120'],
            'damageFormula' => ['sometimes', 'string', 'max:120'],
            'damageParts' => ['sometimes', 'array', 'min:1', 'max:8'],
            'damageParts.*' => ['required', 'array:formula,damageType'],
            'damageParts.*.formula' => ['required', 'string', 'max:120'],
            'damageParts.*.damageType' => ['required', Rule::in(self::DAMAGE_TYPES)],
            'imageUrl' => ['sometimes', 'url', 'max:2048', 'starts_with:https://'],
            'effectUrl' => ['sometimes', 'url', 'max:2048', 'starts_with:https://'],
            'target' => ['sometimes', Rule::in(['self', 'single', 'multiple'])],
            'maxTargets' => ['sometimes', 'integer', 'between:1,50'],
            'rangeFeet' => ['sometimes', 'integer', 'between:0,10000'],
            'economy' => ['sometimes', Rule::in(['action', 'bonus', 'reaction', 'other'])],
            'healingFormula' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'string', 'max:10000'],
            'saveAbility' => ['sometimes', Rule::in(self::ABILITIES)],
            'saveDc' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'saveEffect' => ['required_with:saveAbility', Rule::in(['half', 'none'])],
            'damageType' => ['sometimes', Rule::in(self::DAMAGE_TYPES)],
            'concentration' => ['sometimes', 'boolean'],
            'resourceId' => ['sometimes', 'string', 'max:80'],
            'resourceCost' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'documentId' => ['sometimes', 'integer', 'min:1'],
            'chargeCost' => ['required_with:documentId', 'integer', 'min:1', 'max:1000'],
            'attackAbility' => ['sometimes', Rule::in([...self::ABILITIES, 'spellcasting', 'weapon'])],
            'attackBonus' => ['sometimes', 'integer', 'between:-30,30'],
            'damageAbility' => ['sometimes', Rule::in([...self::ABILITIES, 'spellcasting', 'weapon'])],
            'damageBonus' => ['sometimes', 'integer', 'between:-30,30'],
            'effect' => ['sometimes', 'array:name,target,trigger,duration,modifiers,conditions,iconUrl'],
            'effect.name' => ['required_with:effect', 'string', 'max:160'],
            'effect.iconUrl' => ['sometimes', 'url', 'max:2048', 'starts_with:https://'],
            'effect.target' => ['required_with:effect', Rule::in(['self', 'targets'])],
            'effect.trigger' => ['sometimes', Rule::in(['on-use', 'on-hit', 'on-failed-save'])],
            'effect.duration' => ['required_with:effect', 'array:unit,remaining,phase'],
            'effect.duration.unit' => ['required_with:effect.duration', Rule::in(['rounds', 'minutes', 'hours', 'until-short-rest', 'until-long-rest', 'permanent'])],
            'effect.duration.remaining' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'effect.duration.phase' => ['sometimes', 'in:round,start,end'],
            'effect.modifiers' => ['sometimes', 'array', 'max:30'],
            'effect.modifiers.*.path' => ['required', Rule::in(ActiveEffectEngine::modifierPaths())],
            'effect.modifiers.*.mode' => ['required', Rule::in(['add', 'multiply', 'override'])],
            'effect.modifiers.*.value' => ['required'],
            'effect.conditions' => ['sometimes', 'array', 'max:20'],
            'effect.conditions.*' => ['string', 'max:120'],
        ])->validate();
        foreach (data_get($validated, 'effect.modifiers', []) as $modifier) {
            abort_unless(is_array($modifier) && ActiveEffectEngine::validModifier($modifier), 422, 'Modificador de efeito inválido.');
        }
        foreach (['attackFormula', 'damageFormula', 'healingFormula'] as $formulaField) {
            if (isset($action[$formulaField]) && trim((string) $action[$formulaField]) !== '') {
                abort_unless(app(DiceRoller::class)->isValid((string) $action[$formulaField]), 422, 'Fórmula de '.$formulaField.' inválida.');
            }
        }
        abort_if(! empty($action['damageParts']) && isset($action['damageFormula']), 422, 'Escolha fórmula única ou componentes tipados, não ambos.');
        foreach ($action['damageParts'] ?? [] as $part) {
            abort_unless(app(DiceRoller::class)->isValid((string) $part['formula']), 422, 'Fórmula de componente de dano inválida.');
        }

        return $validated;
    }

    public static function attackFormula(array $system, array $action): ?string
    {
        if (is_string($action['attackFormula'] ?? null) && trim($action['attackFormula']) !== '') {
            return trim($action['attackFormula']);
        }
        if (! is_string($action['attackAbility'] ?? null)) {
            return null;
        }
        $ability = self::resolveAbility($system, $action['attackAbility']);
        $bonus = self::abilityModifier($system, $ability) + (int) ($system['proficiencyBonus'] ?? 2) + (int) ($action['attackBonus'] ?? 0);

        return '1d20'.($bonus >= 0 ? '+' : '').$bonus;
    }

    public static function damageFormula(array $system, array $action): ?string
    {
        $formula = is_string($action['damageFormula'] ?? null) ? trim($action['damageFormula']) : '';
        if ($formula === '') {
            return null;
        }
        if (! is_string($action['damageAbility'] ?? null)) {
            return $formula;
        }
        $ability = self::resolveAbility($system, $action['damageAbility']);
        $bonus = self::abilityModifier($system, $ability) + (int) ($action['damageBonus'] ?? 0);

        return $formula.($bonus > 0 ? '+'.$bonus : ($bonus < 0 ? (string) $bonus : ''));
    }

    private static function resolveAbility(array $system, string $ability): string
    {
        if ($ability === 'spellcasting') {
            $candidate = (string) ($system['spellcastingAbility'] ?? 'int');

            return in_array($candidate, self::ABILITIES, true) ? $candidate : 'int';
        }
        if ($ability === 'weapon') {
            return self::abilityModifier($system, 'dex') > self::abilityModifier($system, 'str') ? 'dex' : 'str';
        }

        return in_array($ability, self::ABILITIES, true) ? $ability : 'str';
    }

    public static function saveDc(array $system, array $action): int
    {
        $ability = $system['spellcastingAbility'] ?? 'int';

        return (int) ($action['saveDc'] ?? (8 + self::abilityModifier($system, $ability) + ($system['proficiencyBonus'] ?? 2)));
    }

    private static function abilityModifier(array $system, string $ability): int
    {
        return (int) floor((($system['abilities'][$ability]['score'] ?? 10) - 10) / 2);
    }

    public static function save(array $system, string $ability, int $dc, array $options, DiceRoller $dice, array $houseRules, string $label, ?\Closure $persistRoll = null): array
    {
        // Monster stat blocks often specify the total, including bespoke bonuses.
        $bonus = $system['saves'][$ability]['bonus'] ?? null;
        $bonus ??= self::abilityModifier($system, $ability) + (($system['saves'][$ability]['proficient'] ?? false) ? ($system['proficiencyBonus'] ?? 2) : 0);
        $bonus += $options['bonus'] ?? 0;
        $conditionMode = ConditionRules::mode($system, 'save', $options['mode'] ?? 'normal', $ability);
        $mode = $conditionMode['mode'];
        $formula = match ($mode) {
            'advantage' => '2d20kh1', 'disadvantage' => '2d20kl1', default => '1d20'
        };
        $adjusted = HouseRules::apply($formula.($bonus >= 0 ? '+' : '').$bonus, $label, $houseRules);
        $effectiveFormula = $adjusted['formula'].($options['effectFormula'] ?? '');
        $roll = $persistRoll ? $persistRoll($effectiveFormula, $adjusted['rules'], $mode) : $dice->roll($effectiveFormula);

        return ['ability' => $ability, 'dc' => $dc, 'success' => $roll['total'] >= $dc, 'roll' => $roll, 'houseRules' => $adjusted['rules'], 'mode' => $mode,
            'conditionSources' => $conditionMode['sources']];
    }

    /** @return array{ac:int,total:int,critical:bool,fumble:bool,hit:bool}|null */
    public static function attack(array $message, array $system): ?array
    {
        $roll = collect($message['rolls'] ?? [])->firstWhere('kind', 'attack');
        if (! $roll) {
            return null;
        }

        $ac = $system['ac'] ?? 10;
        abort_unless(is_numeric($ac), 422, 'Revise a CA da ficha antes de resolver o ataque.');
        $ac = (int) $ac;
        abort_unless($ac >= 0 && $ac <= 99, 422, 'Revise a CA da ficha antes de resolver o ataque.');

        $critical = (bool) ($roll['critical'] ?? false);
        $fumble = (bool) ($roll['fumble'] ?? false);
        $total = (int) ($roll['total'] ?? 0);

        return [
            'ac' => $ac,
            'total' => $total,
            'critical' => $critical,
            'fumble' => $fumble,
            'hit' => ! $fumble && ($critical || $total >= $ac),
        ];
    }

    public static function damage(array $message, array $system, ?array $save, ?float $override = null, string $hitDecision = 'auto', ?int $damageOverride = null, ?int $targetActorId = null): array
    {
        abort_unless(in_array($hitDecision, ['auto', 'hit', 'miss'], true), 422, 'Decisão de ataque inválida.');
        $rolls = collect($message['rolls'] ?? [])->where('kind', 'damage');
        if (($message['spellCast']['kind'] ?? null) === 'missiles') {
            abort_unless($targetActorId !== null, 422, 'Selecione o alvo registrado para resolver os mísseis.');
            $rolls = $rolls->where('targetActorId', $targetActorId);
        }
        $rolls = $rolls->values()->all();
        abort_unless($rolls, 422, 'Esta ação não contém dano.');
        $steps = [];
        $attack = self::attack($message, $system);
        abort_if($hitDecision !== 'auto' && $attack === null, 422, 'Esta ação não possui uma rolagem de ataque.');
        if ($attack) {
            $attack['automaticHit'] = $attack['hit'];
            if ($hitDecision !== 'auto') {
                $attack['hit'] = $hitDecision === 'hit';
                $steps[] = 'Decisão do mestre: '.($attack['hit'] ? 'acerto' : 'erro').' (resultado original preservado)';
            }
        }
        $hit = $attack['hit'] ?? null;
        abort_if($hitDecision === 'miss' && $override !== null && $override > 0, 422, 'Um erro decidido não pode aplicar dano positivo.');
        $pending = isset($message['save']) && $save === null && $hit !== false;
        abort_if($hitDecision === 'miss' && $damageOverride !== null && $damageOverride > 0, 422, 'Um erro decidido não pode aplicar dano positivo.');
        $parts = array_map(fn ($roll) => ['total' => (int) $roll['total'],
            'damageType' => $roll['damageType'] ?? $message['damageType'] ?? null, 'id' => $roll['id'] ?? null], $rolls);
        $outcome = VitalityCalculator::damage($parts, $system, $save, $message['save']['effect'] ?? null, $hit, $override, $damageOverride);

        return [...$outcome, 'steps' => [...$steps, ...$outcome['steps']], 'hit' => $hit, 'attack' => $attack,
            'hitDecision' => $hitDecision, 'pendingSave' => $pending && $override === null && $damageOverride === null,
            'manual' => $override !== null || $damageOverride !== null || $hitDecision !== 'auto'];
    }

    public static function concentrationDc(int $damage, string $ruleset): int
    {
        $dc = max(10, (int) floor($damage / 2));

        return $ruleset === '5e-2024' ? min(30, $dc) : $dc;
    }

    public static function criticalFormula(string $formula): string
    {
        $formula = preg_replace('/\s+/', '', trim($formula));
        abort_unless(preg_match('/^[+-]?\d*d\d+(?:[+-](?:\d*d\d+|\d+))*$/i', $formula), 422, 'Revise a fórmula de dano crítico; use somas de dados e modificadores.');

        // 5e (2014/2024): double each damage die, never a flat modifier.
        return preg_replace_callback('/(\d*)d(\d+)/i', fn ($m) => (2 * (int) ($m[1] === '' ? 1 : $m[1])).'d'.$m[2], $formula);
    }
}
