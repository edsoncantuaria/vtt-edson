<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignModuleController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);

        return response()->json(['modules' => CampaignModule::where('campaign_id', $campaign->id)->orderBy('name')->get()]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->canManage($request->user()), 403);
        $data = $request->validate([
            'moduleId' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'], 'version' => ['required', 'string', 'max:40'],
            'manifest' => ['required', 'array'], 'permissions' => ['sometimes', 'array', 'max:20'],
            'permissions.*' => ['string', 'max:80'], 'enabled' => ['sometimes', 'boolean'],
        ]);
        abort_unless(($data['manifest']['apiVersion'] ?? 1) === 1, 422, 'Versão da Plugin API incompatível.');
        abort_if(isset($data['manifest']['url']) || isset($data['manifest']['script']), 422, 'Módulos de campanha são declarativos; código remoto não é executado.');
        $module = CampaignModule::updateOrCreate(
            ['campaign_id' => $campaign->id, 'module_id' => $data['moduleId']],
            ['name' => $data['name'], 'version' => $data['version'], 'manifest' => $data['manifest'], 'permissions' => $data['permissions'] ?? [], 'enabled' => $data['enabled'] ?? true],
        );

        return response()->json(['module' => $module], 201);
    }

    public function update(Request $request, CampaignModule $campaignModule): JsonResponse
    {
        abort_unless($campaignModule->campaign->canManage($request->user()), 403);
        $data = $request->validate(['enabled' => ['sometimes', 'boolean'], 'permissions' => ['sometimes', 'array', 'max:20'], 'permissions.*' => ['string', 'max:80']]);
        $campaignModule->update($data);

        return response()->json(['module' => $campaignModule->fresh()]);
    }
}
