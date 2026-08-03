<?php

namespace App\Support;

final class SceneStateFactory
{
    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return [
            'grid' => [
                'size' => 70,
                'offsetX' => 0,
                'offsetY' => 0,
                'snap' => true,
            ],
            'backgroundUrl' => null,
            'tokens' => [],
            'walls' => [],
            'doors' => [],
            'lights' => [],
            'fog' => ['revealed' => []],
            'chat' => [],
        ];
    }
}
