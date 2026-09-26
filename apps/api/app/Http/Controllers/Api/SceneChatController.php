<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Game\Dice\RollLedger;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSceneChatRequest;
use App\Models\Scene;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SceneChatController extends Controller
{
    use AuthorizesScene;

    public function store(SendSceneChatRequest $request, Scene $scene, RollLedger $ledger): JsonResponse
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
            try {
                $roll = $ledger->roll($scene, $user, [
                    'requestId' => $data['requestId'] ?? (string) Str::uuid(),
                    'context' => 'custom', 'formula' => $formula, 'label' => $data['label'] ?? null,
                ]);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            $message = $ledger->chatMessage($roll, $user);
            if ($roll['replayed']) {
                return response()->json(['message' => $message, 'state' => $scene->stateFor($user)]);
            }
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
