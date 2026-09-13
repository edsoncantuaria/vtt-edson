<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SendSceneChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:1000'],
            'label' => ['nullable', 'string', 'max:80'],
        ];
    }
}
