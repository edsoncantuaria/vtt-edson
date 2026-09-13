<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSceneChatRequest;
use App\Models\Scene;
use App\Support\Dnd\HouseRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SceneChatController extends Controller
{
    use AuthorizesScene;

    public function store(SendSceneChatRequest $request, Scene $scene, DiceRoller $dice): JsonResponse
    {
        $this->requireParticipant($request, $scene);
        $data = $request->validated();

        $user = $request->user();
        $text = trim($data['text']);
        $message = [
            'id' => (string) Str::uuid(),
            'userId' => $user->id,
            'userName' => $user->name,
            'createdAt' => now()->toIso8601String(),
        ];

        if (Str::startsWith(Str::lower($text), '/roll ')) {
            $formula = trim(substr($text, 6));
            $adjusted = HouseRules::apply($formula, $data['label'] ?? '', $scene->campaign->house_rules ?? []);
            try {
                $result = $dice->roll($adjusted['formula']);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            $message = array_merge($message, [
                'type' => 'roll',
                'houseRules' => $adjusted['rules'],
                'formula' => $result['formula'],
                'total' => $result['total'],
                'detail' => $result['detail'],
                'text' => $result['detail'],
                'critical' => $result['critical'],
                'fumble' => $result['fumble'],
                'label' => $data['label'] ?? null,
            ]);
        } else {
            $message = array_merge($message, ['type' => 'text', 'text' => $text]);
        }

        $state = $scene->state;
        $state['chat'][] = $message;
        $state['chat'] = array_slice($state['chat'], -200);
        $scene->state = $state;
        $scene->save();
        broadcast(new SceneUpdated($scene, 'chat'));

        return response()->json(['message' => $message, 'state' => $scene->stateFor($request->user())]);
    }
}
