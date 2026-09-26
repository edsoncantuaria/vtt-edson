<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\Spellcasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ActorSpellController extends Controller
{
    public function index(Request $request, Actor $actor, Spellcasting $spellcasting): JsonResponse
    {
        $campaign = $actor->campaign;
        abort_unless($campaign->isMember($request->user()) && ($campaign->canManage($request->user())
            || $actor->isOwnedBy($request->user()) || $actor->shared
            || CampaignResourcePermission::permits($campaign, $request->user(), 'actor', $actor->id)), 403);
        $spells = [];
        foreach ($actor->documents()->where('kind', 'spell')->orderBy('sort')->get() as $document) {
            try {
                $profile = $spellcasting->profile($actor, $document);
                // Non-prepared and unsupported entries remain visible in the normal sheet.
                $spells[] = collect($profile)->except('automation')->all();
            } catch (HttpException $exception) {
                // An old/homebrew document is not silently treated as a catalog spell.
                continue;
            }
        }

        return response()->json(['spells' => $spells]);
    }
}
