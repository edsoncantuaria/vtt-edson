<?php

namespace App\Http\Requests;

use App\Models\EncounterBuilderDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InstantiateEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var EncounterBuilderDraft $draft */
        $draft = $this->route('encounterBuilderDraft');

        return [
            'sceneId' => ['required', 'integer', Rule::exists('scenes', 'id')->where('campaign_id', $draft->campaign_id)],
            'requestId' => ['required', 'uuid'],
            'x' => ['nullable', 'numeric', 'between:0,100000'],
            'y' => ['nullable', 'numeric', 'between:0,100000'],
            'hidden' => ['sometimes', 'boolean'],
            'addToCombat' => ['sometimes', 'boolean'],
            'startCombat' => ['sometimes', 'boolean'],
        ];
    }
}
