<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignMacro;
use App\Models\CampaignResourcePermission;
use App\Models\Journal;
use App\Models\Scene;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ResourcePermissionController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->canManage($request->user()), 403);

        return response()->json(['permissions' => CampaignResourcePermission::where('campaign_id', $campaign->id)->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->canManage($request->user()), 403);
        $data = $request->validate([
            'userId' => ['required', 'integer', Rule::exists('campaign_members', 'user_id')->where('campaign_id', $campaign->id)],
            'resourceType' => ['required', Rule::in(['actor', 'journal', 'scene', 'asset', 'macro'])],
            'resourceId' => ['required', 'integer', 'min:1'],
            'permission' => ['required', Rule::in(['view', 'edit', 'manage'])],
        ]);
        $models = [
            'actor' => Actor::class,
            'journal' => Journal::class,
            'scene' => Scene::class,
            'asset' => CampaignAsset::class,
            'macro' => CampaignMacro::class,
        ];
        $model = $models[$data['resourceType']];
        abort_unless($model::query()->whereKey($data['resourceId'])->where('campaign_id', $campaign->id)->exists(), 422, 'O recurso precisa pertencer a esta campanha.');
        abort_unless($campaign->roleFor(User::findOrFail($data['userId'])) !== null, 422, 'A pessoa precisa participar desta campanha.');
        $grant = CampaignResourcePermission::updateOrCreate([
            'campaign_id' => $campaign->id,
            'user_id' => $data['userId'],
            'resource_type' => $data['resourceType'],
            'resource_id' => $data['resourceId'],
        ], ['permission' => $data['permission']]);

        return response()->json(['permission' => $grant], 201);
    }

    public function destroy(Request $request, Campaign $campaign, CampaignResourcePermission $permission): JsonResponse
    {
        abort_unless($campaign->canManage($request->user()), 403);
        abort_unless((int) $permission->campaign_id === (int) $campaign->id, 404);
        $permission->delete();

        return response()->json(['ok' => true]);
    }
}
