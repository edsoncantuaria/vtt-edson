<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignMember;
use App\Models\SceneMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CampaignMemberController extends Controller
{
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);
        $members = $campaign->members()->with('user:id,name,email')->orderBy('id')->get();

        return response()->json(['members' => $members]);
    }

    public function update(Request $request, Campaign $campaign, CampaignMember $campaignMember): JsonResponse
    {
        abort_unless((int) $campaign->owner_id === (int) $request->user()->id, 403, 'Somente o dono da campanha altera papéis.');
        abort_unless((int) $campaignMember->campaign_id === (int) $campaign->id, 404);
        abort_if((int) $campaignMember->user_id === (int) $campaign->owner_id, 422, 'O dono da campanha permanece GM.');
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(['assistant', 'player', 'observer'])],
            'permissions' => ['sometimes', 'array', 'max:30'],
            'permissions.*' => ['string', Rule::in([
                'actors.manage', 'scenes.manage', 'journal.manage', 'catalog.manage', 'homebrew.manage',
                'combat.manage', 'assets.manage', 'macros.manage', 'subsystems.manage', 'campaign-export',
            ])],
        ]);
        $campaignMember->update($data);
        if (isset($data['role'])) {
            SceneMember::query()
                ->where('user_id', $campaignMember->user_id)
                ->whereIn('scene_id', $campaign->scenes()->pluck('id'))
                ->update(['role' => $data['role']]);
        }

        return response()->json(['member' => $campaignMember->fresh()->load('user:id,name,email')]);
    }
}
