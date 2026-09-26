<?php

namespace App\Support\Dnd;

/** Pure, single source of truth for typed damage and hit-point transitions.
 * No dice, persistence or permissions: callers confirm and persist separately.
 */
final class VitalityCalculator
{
    /** @param list<array{total:int|float, damageType?:string|null, id?:string}> $parts */
    public static function damage(array $parts, array $system, ?array $save, ?string $saveEffect, ?bool $hit, ?float $factor = null, ?int $exact = null): array
    {
        $raw = 0;
        $steps = [];
        $components = [];
        $sources = ['Ação registrada: componentes rolados pelo servidor; resolução de dano 5e 2014/2024'];
        foreach ($parts as $index => $part) {
            $base = max(0, (int) $part['total']);
            $raw += $base;
            $type = $part['damageType'] ?? null;
            $amount = $base;
            $trace = ['Rolagem: '.$base];
            if ($hit === false) {
                $amount = 0;
                $trace[] = 'Ataque não acertou: 0';
                $sources[] = 'Rolagem de ataque contra CA do alvo';
            } elseif ($save !== null && $save['success']) {
                $amount = $saveEffect === 'half' ? intdiv($amount, 2) : 0;
                $trace[] = 'Salvaguarda bem-sucedida ('.($saveEffect === 'half' ? 'metade' : 'zero').'): '.$amount;
                $sources[] = 'Salvaguarda da ação: '.($saveEffect === 'half' ? 'metade' : 'zero').' no sucesso';
            }
            if ($type !== null) {
                $traits = $system['damageTraits'] ?? [];
                if (in_array($type, $traits['immune'] ?? [], true)) {
                    $amount = 0;
                    $trace[] = 'Imunidade a '.$type.': 0';
                    $sources[] = 'Ficha do alvo: imunidade a '.$type;
                } else {
                    if (in_array($type, $traits['resist'] ?? [], true)) {
                        $amount = intdiv($amount, 2);
                        $trace[] = 'Resistência a '.$type.': '.$amount;
                        $sources[] = 'Ficha do alvo: resistência a '.$type;
                    }
                    if (in_array($type, $traits['vulnerable'] ?? [], true)) {
                        $amount *= 2;
                        $trace[] = 'Vulnerabilidade a '.$type.': '.$amount;
                        $sources[] = 'Ficha do alvo: vulnerabilidade a '.$type;
                    }
                }
            } else {
                $trace[] = 'Tipo não definido: defesas por tipo não calculadas';
                $sources[] = 'Ação legada sem tipo: decisão da mesa necessária para defesas condicionais';
            }
            $components[] = ['index' => $index, 'rollId' => $part['id'] ?? null, 'damageType' => $type,
                'rolled' => $base, 'calculated' => $amount, 'steps' => $trace];
            $steps[] = ($type ?? 'Dano sem tipo').' #'.($index + 1).': '.implode(' → ', $trace);
        }
        $automatic = array_sum(array_column($components, 'calculated'));
        $reduction = (int) ($system['damageReduction'] ?? 0);
        abort_unless($reduction >= 0 && $reduction <= 100000, 422, 'Redução fixa de dano fora do limite da ficha.');
        $automatic = max(0, $automatic - $reduction);
        if ($reduction > 0) {
            $steps[] = 'Redução fixa da ficha: -'.$reduction.' → '.$automatic;
            $sources[] = 'Ficha do alvo: redução fixa de dano (regra opcional explícita da mesa)';
        }
        $amount = $automatic;
        if ($exact !== null) {
            abort_unless($exact >= 0 && $exact <= 100000, 422, 'Dano corrigido fora do limite.');
            $amount = $exact;
            $steps[] = 'Decisão manual: dano exato '.$amount.' (substitui regras automáticas)';
            $sources[] = 'Decisão auditada do mestre: dano exato';
        } elseif ($factor !== null) {
            $amount = max(0, (int) floor($raw * $factor));
            $steps[] = 'Decisão manual: '.$raw.' × '.$factor.' = '.$amount.' (substitui regras automáticas)';
            $sources[] = 'Decisão auditada do mestre: multiplicador';
        }

        return ['damage' => $amount, 'rolledDamage' => $raw, 'automaticDamage' => $automatic,
            'components' => $components, 'steps' => $steps, 'sources' => array_values(array_unique($sources))];
    }

    public static function applyDamage(array $hp, int $damage): array
    {
        self::validHp($hp);
        abort_unless($damage >= 0 && $damage <= 10000000, 422, 'Dano fora do limite.');
        $absorbed = min((int) ($hp['temp'] ?? 0), $damage);
        $after = $hp;
        $after['temp'] = (int) ($hp['temp'] ?? 0) - $absorbed;
        $after['value'] = max(0, (int) $hp['value'] - ($damage - $absorbed));

        return ['after' => $after, 'applied' => $damage, 'absorbedTemp' => $absorbed,
            'lostHp' => (int) $hp['value'] - $after['value'],
            'steps' => ['PV temporários: '.($hp['temp'] ?? 0).' - '.$absorbed.' = '.$after['temp'],
                'PV: '.$hp['value'].' - '.($damage - $absorbed).' (mínimo 0) = '.$after['value']],
            'sources' => ['Ficha: PV temporários absorvem antes dos PV', 'Regras 5e 2014/2024: PV não ficam negativos']];
    }

    public static function heal(array $hp, int $rolled, ?int $exact = null): array
    {
        self::validHp($hp);
        abort_unless($rolled >= 0 && $rolled <= 1000000 && ($exact === null || ($exact >= 0 && $exact <= 100000)), 422, 'Cura fora do limite.');
        $amount = $exact ?? $rolled;
        $after = $hp;
        $after['value'] = min((int) $hp['max'], (int) $hp['value'] + $amount);
        $restored = $after['value'] - (int) $hp['value'];

        return ['after' => $after, 'rolled' => $rolled, 'requested' => $amount,
            'restored' => $restored, 'excess' => $amount - $restored,
            'steps' => ['Cura rolada: '.$rolled, ...($exact !== null ? ['Decisão do mestre: '.$exact] : []),
                'PV: '.$hp['value'].' + '.$amount.' (máximo '.$hp['max'].') = '.$after['value'],
                'Cura efetiva: '.$restored.'; excedente: '.($amount - $restored),
                'PV temporários não são curados: '.($hp['temp'] ?? 0)],
            'sources' => ['Regras 5e 2014/2024: cura não excede PV máximos e não altera PV temporários',
                ...($exact !== null ? ['Decisão auditada do mestre: cura exata'] : [])]];
    }

    /** Returning from zero HP clears death-save counters and stabilization; healing is not resurrection. */
    public static function healSystem(array $system, int $rolled, ?int $exact = null, bool $character = false): array
    {
        $result = self::heal($system['hp'], $rolled, $exact);
        $result['statusBefore'] = null;
        $result['statusAfter'] = null;
        $conditions = $system['conditions'] ?? [];
        abort_if($character && $result['after']['value'] > 0
            && in_array('morto', array_map('mb_strtolower', $conditions), true),
            422, 'Cura comum não ressuscita personagem morto.');
        if ($character && (int) $system['hp']['value'] === 0 && $result['after']['value'] > 0) {
            $result['statusBefore'] = ['deathSaves' => $system['deathSaves'] ?? ['success' => 0, 'failure' => 0], 'conditions' => $conditions];
            $system['deathSaves'] = ['success' => 0, 'failure' => 0];
            $system['conditions'] = array_values(array_filter($conditions, fn ($condition) => mb_strtolower($condition) !== 'estabilizado'));
            $result['statusAfter'] = ['deathSaves' => $system['deathSaves'], 'conditions' => $system['conditions']];
            $result['steps'][] = 'Retorno de 0 PV: salvaguardas contra morte zeradas; estabilizado removido';
            $result['sources'][] = 'Regras 5e 2014/2024: recuperar PV a partir de zero encerra as salvaguardas contra morte';
        }
        $system['hp'] = $result['after'];
        $result['system'] = $system;

        return $result;
    }

    private static function validHp(array $hp): void
    {
        abort_unless(isset($hp['value'], $hp['max']) && is_numeric($hp['value']) && is_numeric($hp['max'])
            && (int) $hp['max'] >= 0 && (int) $hp['value'] >= 0 && (int) $hp['value'] <= (int) $hp['max']
            && is_numeric($hp['temp'] ?? 0) && (int) ($hp['temp'] ?? 0) >= 0, 422, 'Revise os PV da ficha.');
    }
}
