<?php

namespace App\Http\Controllers\Api;

use App\Game\Dice\RollLedger;
use App\Http\Controllers\Concerns\AuthorizesScene;
use App\Http\Controllers\Controller;
use App\Models\PrivateMessage;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $recipients = $scene->members()->with('user:id,name')->get()
            ->pluck('user')->filter()->unique('id')->values()->map->only(['id', 'name']);

        return response()->json(['messages' => $messages, 'recipients' => $recipients]);
    }

    public function store(Request $request, Scene $scene, RollLedger $ledger): JsonResponse
    {
        $this->requireParticipant($request, $scene);
        $data = $request->validate([
            'recipientUserId' => ['nullable', 'integer', 'exists:users,id'],
            'audience' => ['sometimes', 'in:user,gm'],
            'text' => ['required', 'string', 'max:1000'],
            'requestId' => ['sometimes', 'uuid'],
        ]);
        $recipientId = ($data['audience'] ?? 'user') === 'gm'
            ? (int) $scene->campaign->owner_id
            : (int) ($data['recipientUserId'] ?? 0);
        abort_if(! $recipientId, 422, 'Escolha quem recebe a mensagem privada.');
        abort_unless($scene->memberFor(User::findOrFail($recipientId)) !== null, 422, 'O destinatário não participa desta cena.');
        $text = trim($data['text']);
        $payload = [
            'scene_id' => $scene->id,
            'sender_user_id' => $request->user()->id,
            'recipient_user_id' => $recipientId,
            'kind' => 'text', 'text' => $text,
        ];
        if (str_starts_with(strtolower($text), '/roll ')) {
            try {
                $roll = $ledger->roll($scene, $request->user(), [
                    'requestId' => $data['requestId'] ?? (string) Str::uuid(),
                    'context' => 'custom', 'formula' => trim(substr($text, 6)),
                    'visibility' => 'private', 'recipientUserId' => $recipientId,
                ]);
            } catch (InvalidArgumentException $error) {
                abort(422, $error->getMessage());
            }
            $previous = PrivateMessage::where('roll_id', $roll['id'])->first();
            if ($previous) {
                return response()->json(['message' => $previous->load(['sender:id,name', 'recipient:id,name'])]);
            }
            $payload = [
                ...$payload, 'roll_id' => $roll['id'], 'kind' => 'roll', 'text' => null,
                'formula' => $roll['formula'], 'total' => $roll['total'], 'detail' => $roll['detail'],
                'critical' => $roll['critical'], 'fumble' => $roll['fumble'],
            ];
        }
        $message = PrivateMessage::create($payload)->load(['sender:id,name', 'recipient:id,name']);

        return response()->json(['message' => $message], 201);
    }
}
