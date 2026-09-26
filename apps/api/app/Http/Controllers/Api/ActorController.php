<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\ActorDocumentService;
use App\Support\Dnd\ActorStateFactory;
use App\Support\Dnd\ActorSystemValidator;
use App\Support\Dnd\WizardCreationValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActorController extends Controller
{
    public function __construct(
        private readonly ActorSystemValidator $systemValidator,
        private readonly ActorDocumentService $documents,
        private readonly WizardCreationValidator $wizard,
    ) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->requireMember($request, $campaign);

        $granted = CampaignResourcePermission::query()
            ->where('campaign_id', $campaign->id)
            ->where('user_id', $request->user()->id)
            ->where('resource_type', 'actor')
            ->pluck('resource_id');

        return response()->json(['actors' => $campaign->actors()
            ->when(! $campaign->canManage($request->user()), fn ($query) => $query->where(fn ($q) => $q
                ->where('owner_user_id', $request->user()->id)
                ->orWhere('shared', true)
                ->orWhereIn('id', $granted)))
            ->with(['documents', 'activeEffects'])->orderBy('name')->get()->map->toPayload()]);
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

        if ($role === 'observer') {
            abort(403, 'Observadores possuem acesso somente de leitura.');
        }
        if (! $campaign->canManage($request->user())) {
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
        abort_if(! $campaign->canManage($user) && collect($system['actions'] ?? [])
            ->contains(fn ($action) => ($action['visibility'] ?? 'public') === 'gm'), 403, 'Somente o mestre pode criar ações reservadas.');
        if ($data['type'] === 'character') {
            $this->wizard->validate($request, $campaign, $system);
        }

        $actor = Actor::create([
            'campaign_id' => $campaign->id,
            'owner_user_id' => $ownerUserId,
            'type' => $data['type'],
            'name' => $data['name'],
            'system' => $system,
        ]);
        $this->documents->syncFromLegacy($actor);
        $this->documents->syncLegacy($actor);

        return response()->json(['actor' => $actor->fresh()->toPayload()], 201);
    }

    public function show(Request $request, Actor $actor): JsonResponse
    {
        $role = $this->requireMember($request, $actor->campaign);
        abort_unless($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user()) || $actor->shared
            || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id), 403);

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
                if (! $actor->campaign->canManage($request->user())) {
                    $public = $data['system']['actions'] ?? [];
                    abort_if(collect($public)->contains(fn ($action) => ($action['visibility'] ?? 'public') === 'gm'), 403, 'Somente o mestre pode configurar ações reservadas.');
                    $reserved = array_values(array_filter($actor->system['actions'] ?? [], fn ($action) => ($action['visibility'] ?? 'public') === 'gm'));
                    $reservedIds = array_column($reserved, 'id');
                    abort_if(array_intersect($reservedIds, array_column($public, 'id')), 409, 'Há uma ação reservada com o mesmo identificador. Atualize a ficha.');
                    $data['system']['actions'] = [...$public, ...$reserved];
                }
                abort_if($actor->type === 'character' && (int) data_get($data['system'], 'bio.level', 1) !== (int) data_get($actor->system, 'bio.level', 1), 422,
                    'Use Evoluir personagem para subir de nível; a edição manual da ficha não registra evolução.');
                $actor->system = $data['system'];
            }
            if (array_key_exists('shared', $data)) {
                abort_unless($actor->campaign->canManage($request->user()), 403);
                $actor->shared = $data['shared'];
            }
            if (isset($data['name'])) {
                $actor->name = $data['name'];
            }
            $actor->save();
            if (isset($data['system'])) {
                $this->documents->syncFromLegacy($actor);
                $this->documents->syncLegacy($actor);
            }

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
        return DB::transaction(function () use ($request, $actor) {
            // Token writes serialize on their scene. Acquire those same locks before
            // checking references, so a concurrent placement cannot race deletion.
            $scenes = $actor->campaign->scenes()->orderBy('id')->lockForUpdate()->get(['id', 'state']);
            $actor = Actor::query()->lockForUpdate()->findOrFail($actor->id);
            $this->requireEditRights($request, $actor);
            $referenced = $scenes->contains(fn ($scene) => collect($scene->state['tokens'] ?? [])
                ->contains(fn ($token) => (int) ($token['actorId'] ?? 0) === (int) $actor->id));
            abort_if($referenced, 409, 'Remova os tokens vinculados a esta ficha das cenas antes de apagá-la.');
            $actor->delete();

            return response()->json(['ok' => true]);
        });
    }

    /** Ficha "portátil" pra export/import entre campanhas — sem ids de banco. */
    public function export(Request $request, Actor $actor): JsonResponse
    {
        $role = $this->requireMember($request, $actor->campaign);
        abort_unless($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user()) || $actor->shared
            || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id), 403);

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
        if ($actor->campaign->canManage($request->user())) {
            return;
        }
        if ($role === 'observer') {
            abort(403, 'Observadores possuem acesso somente de leitura.');
        }
        if (! $actor->isOwnedBy($request->user())
            && ! CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id, 'edit')) {
            abort(403, 'Você só pode editar suas próprias fichas.');
        }
    }
}
