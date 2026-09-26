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
            'requestId' => ['sometimes', 'uuid'],
            'targetActorIds' => ['sometimes', 'array', 'max:50'],
            'targetActorIds.*' => ['integer', 'distinct', Rule::exists('actors', 'id')->where('campaign_id', $campaignId)],
        ];
    }
}
