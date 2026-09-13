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
            'vision' => ['dynamic' => false, 'darkness' => false, 'normalVisionFeet' => 60],
            'audio' => ['url' => null, 'volume' => 0.5, 'loop' => true],
            'fog' => ['revealed' => []],
            'chat' => [],
        ];
    }
}
