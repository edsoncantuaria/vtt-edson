<?php

namespace App\Http\Requests;

use App\Models\Scene;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpsertSceneTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Scene $scene */
        $scene = $this->route('scene');

        return [
            'id' => ['nullable', 'string', 'max:64'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'name' => ['nullable', 'string', 'max:80'],
            'ownerUserId' => ['nullable', 'integer'],
            'size' => ['nullable', 'numeric', 'min:0.25', 'max:20'],
            'appearance' => ['sometimes', 'array:border,background,zoom,x,y'],
            'appearance.border' => ['required_with:appearance', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.background' => ['required_with:appearance', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'appearance.zoom' => ['required_with:appearance', 'numeric', 'between:1,4'],
            'appearance.x' => ['required_with:appearance', 'numeric', 'between:-1,1'],
            'appearance.y' => ['required_with:appearance', 'numeric', 'between:-1,1'],
            'hidden' => ['sometimes', 'boolean'],
            'stealthDc' => ['nullable', 'integer', 'between:1,40'],
            'actorId' => ['nullable', 'integer', Rule::exists('actors', 'id')->where('campaign_id', $scene->campaign_id)],
        ];
    }
}
