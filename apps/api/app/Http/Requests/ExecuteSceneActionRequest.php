<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExecuteSceneActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $campaignId = $this->route('scene')->campaign_id;

        return [
            'actorId' => ['required', 'integer', Rule::exists('actors', 'id')->where('campaign_id', $campaignId)],
            'actionId' => ['required', 'string', 'max:80'],
            'mode' => ['sometimes', 'in:normal,advantage,disadvantage'],
            // Legacy clients may omit the key; current UI always supplies it.
            'requestId' => ['sometimes', 'uuid'],
            'targetActorIds' => ['sometimes', 'array', 'max:50'],
            'targetActorIds.*' => ['integer', 'distinct', Rule::exists('actors', 'id')->where('campaign_id', $campaignId)],
            'targetTokenIds' => ['sometimes', 'array', 'max:50'],
            'targetTokenIds.*' => ['string', 'min:1', 'max:80', 'distinct'],
            'spellCast' => ['sometimes', 'array:slotLevel,ritual,missiles,centerTokenId,componentsConfirmed'],
            'spellCast.slotLevel' => ['sometimes', 'nullable', 'integer', 'between:1,9'],
            'spellCast.ritual' => ['sometimes', 'boolean'],
            'spellCast.componentsConfirmed' => ['sometimes', 'boolean'],
            'spellCast.missiles' => ['sometimes', 'array', 'max:11'],
            'spellCast.missiles.*' => ['array:tokenId,count'],
            'spellCast.missiles.*.tokenId' => ['required_with:spellCast.missiles', 'string', 'min:1', 'max:80', 'distinct'],
            'spellCast.missiles.*.count' => ['required_with:spellCast.missiles', 'integer', 'between:1,11'],
            'spellCast.centerTokenId' => ['sometimes', 'string', 'min:1', 'max:80'],
        ];
    }
}
