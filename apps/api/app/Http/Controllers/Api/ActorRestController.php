<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Actor;
use App\Models\CampaignResourcePermission;
use App\Support\Dnd\ActiveEffectEngine;
use App\Support\Dnd\ActorDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ActorRestController extends Controller
{
    public function __construct(
        private readonly ActiveEffectEngine $effects,
        private readonly ActorDocumentService $documents,
    ) {}

    public function store(Request $request, Actor $actor): JsonResponse
    {
        abort_unless($actor->campaign->roleFor($request->user()) !== 'observer'
            && ($actor->campaign->canManage($request->user()) || $actor->isOwnedBy($request->user())
                || CampaignResourcePermission::permits($actor->campaign, $request->user(), 'actor', $actor->id, 'edit')), 403);
        $data = $request->validate(['rest' => ['required', 'in:short,long']]);
        $rest = $data['rest'];
        $system = $actor->system;

        foreach ($system['resources'] ?? [] as $index => $resource) {
            if (($resource['reset'] ?? 'manual') === $rest || ($rest === 'long' && ($resource['reset'] ?? '') === 'short')) {
                $system['resources'][$index]['used'] = 0;
            }
        }
        if ($rest === 'long') {
            foreach ($system['spells']['slots'] ?? [] as $level => $slot) {
                $system['spells']['slots'][$level]['used'] = 0;
            }
            $system['hp']['value'] = $system['hp']['max'];
            $system['hp']['temp'] = 0;
            $system['deathSaves'] = ['success' => 0, 'failure' => 0];
            $hitDice = $system['hitDice'] ?? ['total' => 1, 'used' => 0];
            $recovered = $actor->campaign->ruleset === '5e-2024'
                ? (int) ($hitDice['used'] ?? 0)
                : min((int) ($hitDice['used'] ?? 0), max(1, (int) floor(($hitDice['total'] ?? 1) / 2)));
            $system['hitDice']['used'] = max(0, (int) ($hitDice['used'] ?? 0) - $recovered);
        }
        $actor->system = $system;
        $actor->save();
        $this->effects->clearForRest($actor, $rest);
        $this->documents->resetCharges($actor, $rest);

        return response()->json(['actor' => $actor->fresh()->toPayload()]);
    }
}
