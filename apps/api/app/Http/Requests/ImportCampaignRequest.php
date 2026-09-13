<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archive' => ['required', 'array'],
            'archive.format' => ['required', 'in:vtt-edson-campaign'],
            'archive.version' => ['required', 'integer', 'between:1,3'],
            'archive.campaign' => ['required', 'array'],
            'archive.actors' => ['present', 'array'],
            'archive.scenes' => ['required', 'array', 'min:1'],
            'archive.journals' => ['present', 'array'],
            'archive.actions' => ['sometimes', 'array'],
            'archive.damageApplications' => ['sometimes', 'array'],
            'archive.catalogRefs' => ['sometimes', 'array'],
            'archive.catalogRefs.*' => ['nullable', 'string', 'max:160'],
            'archive.catalogShares' => ['sometimes', 'array'],
            'archive.combats' => ['sometimes', 'array'],
            'archive.playlists' => ['sometimes', 'array'],
            'archive.privateMessages' => ['sometimes', 'array'],
            'archive.homebrewPackages' => ['sometimes', 'array'],
            'archive.encounterDrafts' => ['sometimes', 'array'],
            'archive.rollTables' => ['sometimes', 'array'],
            'archive.lootResults' => ['sometimes', 'array'],
            'archive.media' => ['sometimes', 'array'],
            'archive.actorDocuments' => ['sometimes', 'array'],
            'archive.activeEffects' => ['sometimes', 'array'],
            'archive.assets' => ['sometimes', 'array'],
            'archive.macros' => ['sometimes', 'array'],
            'archive.modules' => ['sometimes', 'array'],
            'archive.subsystems' => ['sometimes', 'array'],
            'name' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
