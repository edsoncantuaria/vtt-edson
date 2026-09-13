<?php

namespace App\Http\Controllers\Api;

use App\Game\Dice\DiceRoller;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseRuleController extends Controller
{
    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($campaign->isMember($request->user()), 403);

        return response()->json(['rules' => $campaign->house_rules ?? []]);
    }

    public function update(Request $request, Campaign $campaign, DiceRoller $dice): JsonResponse
    {
        abort_unless($campaign->roleFor($request->user()) === 'gm', 403);
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:12'], 'rules.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'rules.*.name' => ['required', 'string', 'max:100'], 'rules.*.match' => ['required', 'string', 'min:2', 'max:100'],
            'rules.*.modifier' => ['required', 'integer', 'min:-100', 'max:100'], 'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.formula' => ['nullable', 'string', 'max:80'],
        ]);
        foreach ($data['rules'] as $rule) {
            if (! empty($rule['formula'])) {
                try {
                    $dice->roll($rule['formula']);
                } catch (\InvalidArgumentException $e) {
                    abort(422, $e->getMessage());
                }
            }
        }
        $campaign->house_rules = $data['rules'];
        $campaign->save();

        return response()->json(['rules' => $campaign->house_rules]);
    }
}
