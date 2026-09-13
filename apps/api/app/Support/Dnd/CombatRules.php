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
        return Validator::make($action, [
            'saveAbility' => ['sometimes', Rule::in(self::ABILITIES)],
            'saveDc' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'saveEffect' => ['required_with:saveAbility', Rule::in(['half', 'none'])],
            'damageType' => ['sometimes', Rule::in(self::DAMAGE_TYPES)],
            'concentration' => ['sometimes', 'boolean'],
            'resourceId' => ['sometimes', 'string', 'max:80'],
            'resourceCost' => ['required_with:resourceId', 'integer', 'min:1', 'max:1000'],
        ])->validate();
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

    public static function save(array $system, string $ability, int $dc, array $options, DiceRoller $dice, array $houseRules, string $label): array
    {
        // Monster stat blocks often specify the total, including bespoke bonuses.
        $bonus = $system['saves'][$ability]['bonus'] ?? null;
        $bonus ??= self::abilityModifier($system, $ability) + (($system['saves'][$ability]['proficient'] ?? false) ? ($system['proficiencyBonus'] ?? 2) : 0);
        $bonus += $options['bonus'] ?? 0;
        $mode = $options['mode'] ?? 'normal';
        $formula = match ($mode) {
            'advantage' => '2d20kh1', 'disadvantage' => '2d20kl1', default => '1d20'
        };
        $adjusted = HouseRules::apply($formula.($bonus >= 0 ? '+' : '').$bonus, $label, $houseRules);
        $roll = $dice->roll($adjusted['formula']);

        return ['ability' => $ability, 'dc' => $dc, 'success' => $roll['total'] >= $dc, 'roll' => $roll, 'houseRules' => $adjusted['rules'], 'mode' => $mode];
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

    public static function damage(array $message, array $system, ?array $save, ?float $override = null): array
    {
        $roll = collect($message['rolls'] ?? [])->firstWhere('kind', 'damage');
        abort_unless($roll, 422, 'Esta ação não contém dano.');
        $amount = max(0, (int) $roll['total']);
        $steps = ['Dano rolado: '.$amount];
        $attack = self::attack($message, $system);
        $hit = $attack['hit'] ?? null;
        $pending = isset($message['save']) && $save === null && $hit !== false;
        if ($override !== null) {
            $amount = max(0, (int) floor($amount * $override));
            $steps[] = 'Decisão manual: ×'.$override.' (substitui salvaguarda, acerto e defesas)';
        } else {
            if ($hit === false) {
                $amount = 0;
                $steps[] = 'Ataque não alcançou a CA atual';
            }
            if ($save !== null && $save['success']) {
                $amount = $message['save']['effect'] === 'half' ? (int) floor($amount / 2) : 0;
                $steps[] = 'Salvaguarda bem-sucedida: '.$amount;
            }
            $type = $message['damageType'] ?? null;
            if ($type) {
                $defenses = $system['damageTraits'] ?? [];
                if (in_array($type, $defenses['immune'] ?? [], true)) {
                    $amount = 0;
                    $steps[] = 'Imunidade: 0';
                } else {
                    if (in_array($type, $defenses['resist'] ?? [], true)) {
                        $amount = (int) floor($amount / 2);
                        $steps[] = 'Resistência: '.$amount;
                    }
                    if (in_array($type, $defenses['vulnerable'] ?? [], true)) {
                        $amount *= 2;
                        $steps[] = 'Vulnerabilidade: '.$amount;
                    }
                }
            } else {
                $steps[] = 'Tipo de dano não definido; defesas não calculadas';
            }
        }

        return ['damage' => $amount, 'steps' => $steps, 'hit' => $hit, 'attack' => $attack, 'pendingSave' => $pending && $override === null, 'manual' => $override !== null];
    }

    public static function concentrationDc(int $damage, string $ruleset): int
    {
        $dc = max(10, (int) floor($damage / 2));

        return $ruleset === '5e-2024' ? min(30, $dc) : $dc;
    }

    public static function criticalFormula(string $formula): string
    {
        abort_unless(preg_match('/^(\d*)d(\d+)([+-]\d+)?$/i', trim($formula), $m), 422, 'Revise a fórmula de dano crítico; use NdM±K.');

        return ((int) ($m[1] !== '' ? $m[1] : 1) * 2).'d'.$m[2].($m[3] ?? '');
    }
}
