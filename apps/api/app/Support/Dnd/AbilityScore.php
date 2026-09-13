<?php

namespace App\Support\Dnd;

final class AbilityScore
{
    public static function modifier(int $score): int
    {
        return (int) floor(($score - 10) / 2);
    }
}
