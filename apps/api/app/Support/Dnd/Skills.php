<?php

namespace App\Support\Dnd;

/** Perícias 5e SRD → habilidade associada. Fonte única compartilhada pelos factories/validações. */
final class Skills
{
    public const MAP = [
        'acrobatics' => 'dex',
        'animalHandling' => 'wis',
        'arcana' => 'int',
        'athletics' => 'str',
        'deception' => 'cha',
        'history' => 'int',
        'insight' => 'wis',
        'intimidation' => 'cha',
        'investigation' => 'int',
        'medicine' => 'wis',
        'nature' => 'int',
        'perception' => 'wis',
        'performance' => 'cha',
        'persuasion' => 'cha',
        'religion' => 'int',
        'sleightOfHand' => 'dex',
        'stealth' => 'dex',
        'survival' => 'wis',
    ];
}
