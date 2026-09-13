<?php

namespace App\Support\Dnd;

final class ActorStateFactory
{
    /**
     * @return array<string, mixed>
     */
    public static function character(): array
    {
        $abilities = [];
        foreach (['str', 'dex', 'con', 'int', 'wis', 'cha'] as $key) {
            $abilities[$key] = ['score' => 10];
        }

        $skills = [];
        foreach (array_keys(Skills::MAP) as $skill) {
            $skills[$skill] = ['proficient' => false, 'expertise' => false];
        }

        $saves = [];
        foreach (['str', 'dex', 'con', 'int', 'wis', 'cha'] as $key) {
            $saves[$key] = ['proficient' => false];
        }

        return [
            'abilities' => $abilities,
            'proficiencyBonus' => 2,
            'hp' => ['value' => 10, 'max' => 10, 'temp' => 0],
            'ac' => 10,
            'speed' => 30,
            'skills' => $skills,
            'saves' => $saves,
            'senses' => ['darkvision' => 0],
            'languages' => ['Comum'],
            'inventory' => [],
            'spells' => ['slots' => [], 'known' => []],
            'actions' => [],
            'resources' => [],
            'features' => [],
            'progression' => null,
            'bio' => ['class' => '', 'level' => 1, 'race' => '', 'background' => '', 'alignment' => '', 'notes' => ''],
            'currency' => ['cp' => 0, 'sp' => 0, 'ep' => 0, 'gp' => 0, 'pp' => 0],
        ];
    }

    public static function monster(): array
    {
        $state = self::character();
        $state['bio'] = ['class' => '', 'level' => 1, 'race' => '', 'background' => '', 'alignment' => '', 'notes' => ''];

        return $state;
    }
}
