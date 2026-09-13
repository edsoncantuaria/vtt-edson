<?php

namespace App\Http\Controllers\Api;

use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\PrivateMessage;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PrivateMessageController extends Controller
{
    use AuthorizesScene;

    public function index(Request $request, Scene $scene): JsonResponse
    {
        $this->requireMember($request, $scene);
        $user = $request->user();
        $messages = PrivateMessage::query()->where('scene_id', $scene->id)
            ->where(fn ($query) => $query->where('sender_user_id', $user->id)->orWhere('recipient_user_id', $user->id))
            ->with(['sender:id,name', 'recipient:id,name'])
            ->latest('id')->limit(100)->get()->reverse()->values();
        $recipients = $scene->campaign->scenes()->with('members.user:id,name')->get()->flatMap->members
            ->pluck('user')->filter()->unique('id')->values()->map->only(['id', 'name']);

        return response()->json(['messages' => $messages, 'recipients' => $recipients]);
    }

    public function store(Request $request, Scene $scene, DiceRoller $dice): JsonResponse
    {
        $this->requireMember($request, $scene);
        $data = $request->validate([
            'recipientUserId' => ['nullable', 'integer', 'exists:users,id'],
            'audience' => ['sometimes', 'in:user,gm'],
            'text' => ['required', 'string', 'max:1000'],
        ]);
        $recipientId = ($data['audience'] ?? 'user') === 'gm'
            ? (int) $scene->campaign->owner_id
            : (int) ($data['recipientUserId'] ?? 0);
        abort_if(! $recipientId, 422, 'Escolha quem recebe a mensagem privada.');
        abort_unless($scene->campaign->roleFor(User::findOrFail($recipientId)) !== null, 422, 'O destinatário não participa desta campanha.');
        $text = trim($data['text']);
        $payload = [
            'scene_id' => $scene->id,
            'sender_user_id' => $request->user()->id,
            'recipient_user_id' => $recipientId,
            'kind' => 'text', 'text' => $text,
        ];
        if (str_starts_with(strtolower($text), '/roll ')) {
            try {
                $roll = $dice->roll(trim(substr($text, 6)));
            } catch (InvalidArgumentException $error) {
                abort(422, $error->getMessage());
            }
            $payload = [...$payload, 'kind' => 'roll', 'text' => null, ...$roll];
        }
        $message = PrivateMessage::create($payload)->load(['sender:id,name', 'recipient:id,name']);

        return response()->json(['message' => $message], 201);
    }
}
