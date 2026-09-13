<?php

namespace App\Http\Controllers\Api;

use App\Events\SceneUpdated;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignResourcePermission;
use App\Models\Scene;
use App\Models\SceneMember;
use App\Models\User;
use App\Support\SceneStateFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignSceneController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $role = $campaign->roleFor($request->user()) ?? abort(403, 'Você não faz parte desta campanha.');
        $granted = CampaignResourcePermission::query()->where('campaign_id', $campaign->id)->where('user_id', $request->user()->id)
            ->where('resource_type', 'scene')->pluck('resource_id');
        $scenes = $campaign->scenes()
            ->when(! $campaign->canManage($request->user()), fn ($query) => $query->where(fn ($visible) => $visible->where('published', true)->orWhereIn('id', $granted)))
            ->whereHas('members', fn ($query) => $query->where('user_id', $request->user()->id))
            ->orderBy('id')->get()->map(fn (Scene $scene) => $this->payload($scene, $role, $request->user()));

        return response()->json(['scenes' => $scenes]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $this->gm($request, $campaign);
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $scene = $campaign->scenes()->create(['name' => $data['name'], 'published' => false, 'state' => SceneStateFactory::empty()]);
        foreach ($campaign->members()->get() as $member) {
            SceneMember::firstOrCreate(['scene_id' => $scene->id, 'user_id' => $member->user_id], ['role' => $member->role]);
        }

        return response()->json(['scene' => $this->payload($scene, $campaign->roleFor($request->user()) ?? 'assistant', $request->user())], 201);
    }

    public function update(Request $request, Scene $scene): JsonResponse
    {
        $role = $scene->campaign->roleFor($request->user());
        abort_if($role === null || $role === 'observer', 403, 'Sem permissão para editar esta cena.');
        abort_unless($scene->campaign->canManage($request->user())
            || CampaignResourcePermission::permits($scene->campaign, $request->user(), 'scene', $scene->id, 'edit'), 403, 'Sem permissão para editar esta cena.');
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:120'], 'published' => ['sometimes', 'boolean'], 'preparationReviewed' => ['sometimes', 'boolean']]);
        if (isset($data['published']) && ! $data['published']) {
            abort_unless($scene->campaign->scenes()->where('published', true)->where('id', '!=', $scene->id)->exists(), 422, 'Mantenha ao menos uma cena publicada para o grupo.');
        }
        if (array_key_exists('preparationReviewed', $data)) {
            $state = $scene->state;
            abort_unless(isset($state['preparation']), 422, 'Esta cena não veio de uma importação de aventura.');
            $state['preparation']['reviewed'] = $data['preparationReviewed'];
            $scene->state = $state;
            unset($data['preparationReviewed']);
        }
        if (($data['published'] ?? false) && isset($scene->state['preparation'])) {
            abort_unless($scene->state['preparation']['reviewed'], 422, 'Revise escala, segredos e geometria do mapa antes de publicar.');
        }
        $scene->fill($data)->save();
        broadcast(new SceneUpdated($scene, 'publication'));

        return response()->json(['scene' => $this->payload($scene, $scene->campaign->roleFor($request->user()) ?? 'assistant', $request->user())]);
    }

    private function gm(Request $request, Campaign $campaign): void
    {
        if (! $campaign->canManage($request->user()) && ! $campaign->can($request->user(), 'scenes.manage')) {
            abort(403, 'Apenas o GM pode organizar cenas.');
        }
    }

    private function payload(Scene $scene, string $role, User $user): array
    {
        return ['id' => $scene->id, 'published' => (bool) $scene->published, 'name' => $scene->name, 'role' => $role, 'canEdit' => $role !== 'observer' && ($scene->campaign->canManage($user) || CampaignResourcePermission::permits($scene->campaign, $user, 'scene', $scene->id, 'edit')), 'state' => $scene->stateFor($user), 'backgroundUrl' => $scene->background_path ? url('storage/'.$scene->background_path) : ($scene->state['backgroundUrl'] ?? null)];
    }
}
