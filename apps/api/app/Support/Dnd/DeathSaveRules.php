<?php

namespace App\Support\Dnd;

/** Applies a natural d20 death save. The dice result must come from the server. */
final class DeathSaveRules
{
    /** @return array{system: array<string, mixed>, outcome: string} */
    public static function resolve(array $system, int $natural): array
    {
        if ($natural < 1 || $natural > 20) {
            throw new \InvalidArgumentException('Resultado natural fora de 1 a 20.');
        }
        $success = (int) ($system['deathSaves']['success'] ?? 0);
        $failure = (int) ($system['deathSaves']['failure'] ?? 0);
        if ($natural === 20) {
            $system['hp']['value'] = 1;
            $system['deathSaves'] = ['success' => 0, 'failure' => 0];
            $system['conditions'] = array_values(array_filter($system['conditions'] ?? [], fn ($condition) => ! in_array(mb_strtolower($condition), ['estabilizado', 'morto'], true)));

            return ['system' => $system, 'outcome' => '20 natural: recupera 1 PV e zera os marcadores de morte.'];
        }
        if ($natural >= 10) {
            $success = min(3, $success + 1);
            $outcome = 'Sucesso na salvaguarda contra morte.';
            if ($success === 3) {
                $system['conditions'] = array_values(array_unique([...($system['conditions'] ?? []), 'estabilizado']));
                $outcome = 'Terceiro sucesso: personagem estabilizado com 0 PV.';
            }
        } else {
            $failure = min(3, $failure + ($natural === 1 ? 2 : 1));
            $outcome = $natural === 1 ? '1 natural: duas falhas na salvaguarda contra morte.' : 'Falha na salvaguarda contra morte.';
            if ($failure === 3) {
                $system['conditions'] = array_values(array_unique([...($system['conditions'] ?? []), 'morto']));
                $outcome .= ' Terceira falha: personagem morto.';
            }
        }
        $system['deathSaves'] = ['success' => $success, 'failure' => $failure];

        return ['system' => $system, 'outcome' => $outcome];
    }
}
