<?php

namespace App\Support\Dnd;

use Illuminate\Support\Str;

final class HouseRules
{
    public static function apply(string $formula, string $label, array $rules): array
    {
        $applied = [];
        $bonus = 0;
        foreach ($rules as $rule) {
            if (! ($rule['enabled'] ?? false)) {
                continue;
            }
            $match = Str::lower(Str::ascii($rule['match']));
            if (! str_contains(Str::lower(Str::ascii($label)), $match)) {
                continue;
            }
            if (! empty($rule['formula'])) {
                $formula = $rule['formula'];
            }
            $bonus += (int) ($rule['modifier'] ?? 0);
            $applied[] = $rule['name'];
        }
        if ($bonus !== 0) {
            // Dice grammar has one constant modifier; fold instead of appending a second term.
            $formula = preg_replace_callback('/([+-]\s*\d+)\s*$/', function ($m) use (&$bonus) {
                $bonus += (int) str_replace(' ', '', $m[1]);

                return '';
            }, $formula);
            $formula = trim($formula).($bonus >= 0 ? '+' : '').$bonus;
        }

        return ['formula' => $formula, 'rules' => $applied];
    }
}
