<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExecuteSceneActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'actorId' => ['required', 'integer', 'exists:actors,id'],
            'actionId' => ['required', 'string', 'max:80'],
            'mode' => ['sometimes', 'in:normal,advantage,disadvantage'],
            'requestId' => ['sometimes', 'uuid'],
            'targetActorIds' => ['sometimes', 'array', 'max:50'],
            'targetActorIds.*' => ['integer', 'distinct', 'exists:actors,id'],
        ];
    }
}
