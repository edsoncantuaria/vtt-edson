<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\ActorDocument;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\ResourceAudit;
use App\Support\Dnd\ResourcePool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ActorResourceController extends Controller
{
    public function index(Request $request, Actor $actor): JsonResponse
    {
        $this->requireEdit($request, $actor);
        $gm = $actor->campaign->canManage($request->user());
        $events = DB::table('actor_resource_events')->where('actor_id', $actor->id)
            ->when(! $gm, fn ($query) => $query->where('user_id', $request->user()->id)->whereIn('event', ['adjust', 'rest']))
            ->orderByDesc('id')->limit(60)->get();

        return response()->json(['pools' => ResourceAudit::snapshot($actor), 'events' => $events]);
    }

    public function adjust(Request $request, Actor $actor): JsonResponse
    {
        return DB::transaction(function () use ($request, $actor) {
            $actor = Actor::query()->lockForUpdate()->findOrFail($actor->id);
            $this->requireEdit($request, $actor);
            $data = $request->validate([
                'requestId' => ['required', 'uuid'], 'resourceId' => ['required', 'string', 'max:100'],
                'current' => ['required', 'integer', 'between:0,100000'],
                'reason' => ['sometimes', 'nullable', 'string', 'max:240'],
            ]);
            $gm = $actor->campaign->canManage($request->user());
            if ($gm) {
                abort_unless(trim((string) ($data['reason'] ?? '')) !== '', 422, 'Informe o motivo do ajuste pelo mestre.');
            }
            $hash = hash('sha256', json_encode([$data['resourceId'], $data['current'], $data['reason'] ?? null], JSON_THROW_ON_ERROR));
            $previous = DB::table('actor_resource_events')->where(['actor_id' => $actor->id, 'request_id' => $data['requestId']])->first();
            if ($previous) {
                abort_unless($previous->event === 'adjust' && hash_equals((string) $previous->request_hash, $hash), 409,
                    'Esta chave já foi utilizada com outro ajuste.');

                return response()->json(['actor' => $actor->toPayload(), 'replayed' => true]);
            }
            $before = ResourceAudit::snapshot($actor);
            $pool = collect($before)->firstWhere('id', $data['resourceId']);
            abort_unless($pool, 422, 'Recurso não pertence a esta ficha.');
            abort_if($pool['current'] === $data['current'], 422, 'O recurso já possui essa quantidade.');
            if (! $gm) {
                abort_if($data['resourceId'] === 'inspiration' && $data['current'] > $pool['current'], 403,
                    'Conceder Inspiração requer decisão do mestre.');
                abort_unless(abs($pool['current'] - $data['current']) === 1, 403,
                    'Ajustes de múltiplos usos exigem decisão do mestre.');
            }
            if (str_starts_with($data['resourceId'], 'document:')) {
                $id = (int) substr($data['resourceId'], 9);
                $document = ActorDocument::query()->where('actor_id', $actor->id)->lockForUpdate()->findOrFail($id);
                $charges = $document->charges;
                abort_unless($charges && $data['current'] <= (int) $charges['max'], 422, 'Cargas fora do limite.');
                $charges['value'] = $data['current'];
                $document->charges = $charges;
                $document->save();
            } else {
                try {
                    $actor->system = ResourcePool::adjust($actor->system, $data['resourceId'], $data['current']);
                } catch (InvalidArgumentException $exception) {
                    abort(422, $exception->getMessage());
                }
                $actor->save();
            }
            ResourceAudit::record($actor, 'adjust', $before, ResourceAudit::snapshot($actor), $request->user()->id,
                $data['requestId'], $hash, $data['resourceId'], $data['reason'] ?? 'Ajuste de um uso na ficha');

            return response()->json(['actor' => $actor->fresh()->toPayload(), 'replayed' => false]);
        });
    }

    private function requireEdit(Request $request, Actor $actor): void
    {
        abort_unless($actor->campaign->roleFor($request->user()) !== null
            && $actor->campaign->roleFor($request->user()) !== 'observer'
            && ($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user())
                || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id, 'edit')), 403);
    }
}
