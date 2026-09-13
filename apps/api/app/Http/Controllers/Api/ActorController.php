<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Campaign;
use App\Support\Dnd\ActorStateFactory;
use App\Support\Dnd\ActorSystemValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActorController extends Controller
{
    public function __construct(private readonly ActorSystemValidator $systemValidator) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->requireMember($request, $campaign);

        return response()->json(['actors' => $campaign->actors()->when($campaign->roleFor($request->user()) !== 'gm', fn ($query) => $query->where(fn ($q) => $q->where('owner_user_id', $request->user()->id)->orWhere('shared', true)))->orderBy('name')->get()->map->toPayload()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $role = $this->requireMember($request, $campaign);
        $user = $request->user();

        $data = $request->validate([
            'type' => ['required', 'in:character,npc,monster'],
            'name' => ['required', 'string', 'max:120'],
            'ownerUserId' => ['nullable', 'integer'],
            'system' => ['nullable', 'array'],
        ]);

        if ($role !== 'gm') {
            // Jogador só pode criar personagem próprio.
            if ($data['type'] !== 'character') {
                abort(403, 'Apenas o GM pode criar NPCs e monstros.');
            }
            $ownerUserId = $user->id;
        } else {
            $ownerUserId = $data['ownerUserId'] ?? ($data['type'] === 'character' ? $user->id : null);
        }

        $this->systemValidator->validate($request);
        $system = $data['system'] ?? ($data['type'] === 'monster' ? ActorStateFactory::monster() : ActorStateFactory::character());

        $actor = Actor::create([
            'campaign_id' => $campaign->id,
            'owner_user_id' => $ownerUserId,
            'type' => $data['type'],
            'name' => $data['name'],
            'system' => $system,
        ]);

        return response()->json(['actor' => $actor->toPayload()], 201);
    }

    public function show(Request $request, Actor $actor): JsonResponse
    {
        $role = $this->requireMember($request, $actor->campaign);
        abort_unless($role === 'gm' || $actor->isOwnedBy($request->user()) || $actor->shared, 403);

        return response()->json(['actor' => $actor->toPayload()]);
    }

    public function update(Request $request, Actor $actor): JsonResponse
    {
        return DB::transaction(function () use ($request, $actor) {
            $actor = Actor::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->requireEditRights($request, $actor);
            $data = $request->validate([
                'name' => ['nullable', 'string', 'max:120'],
                'shared' => ['sometimes', 'boolean'],
                'system' => ['nullable', 'array'],
                'revision' => ['required_with:system', 'integer', 'min:0'],
            ]);
            if (isset($data['system'])) {
                abort_unless($data['revision'] === $actor->revision, 409, 'A ficha mudou. Recarregue antes de salvar para preservar as ações da mesa.');
                $this->systemValidator->validate($request);
                $actor->system = $data['system'];
            }
            if (array_key_exists('shared', $data)) {
                abort_unless($actor->campaign->roleFor($request->user()) === 'gm', 403);
                $actor->shared = $data['shared'];
            }
            if (isset($data['name'])) {
                $actor->name = $data['name'];
            }
            $actor->save();

            return response()->json(['actor' => $actor->toPayload()]);
        });
    }

    public function uploadImage(Request $request, Actor $actor): JsonResponse
    {
        $this->requireEditRights($request, $actor);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']]);
        $actor->img_path = $request->file('image')->store('actors/'.$actor->id, 'public');
        $actor->save();

        return response()->json(['actor' => $actor->toPayload()]);
    }

    public function destroy(Request $request, Actor $actor): JsonResponse
    {
        $this->requireEditRights($request, $actor);
        $actor->delete();

        return response()->json(['ok' => true]);
    }

    /** Ficha "portátil" pra export/import entre campanhas — sem ids de banco. */
    public function export(Request $request, Actor $actor): JsonResponse
    {
        $role = $this->requireMember($request, $actor->campaign);
        abort_unless($role === 'gm' || $actor->isOwnedBy($request->user()) || $actor->shared, 403);

        return response()->json([
            'type' => $actor->type,
            'name' => $actor->name,
            'system' => $actor->toPayload()['system'],
        ]);
    }

    private function requireMember(Request $request, Campaign $campaign): string
    {
        $role = $campaign->roleFor($request->user());
        if ($role === null) {
            abort(403, 'Você não faz parte desta campanha.');
        }

        return $role;
    }

    private function requireEditRights(Request $request, Actor $actor): void
    {
        $role = $this->requireMember($request, $actor->campaign);
        if ($role === 'gm') {
            return;
        }
        if (! $actor->isOwnedBy($request->user())) {
            abort(403, 'Você só pode editar suas próprias fichas.');
        }
    }
}
