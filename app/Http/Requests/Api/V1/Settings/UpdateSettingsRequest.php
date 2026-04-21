<?php

namespace App\Http\Requests\Api\V1\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reminder_email' => ['nullable', 'email', 'max:190'],
            'quick_percentages' => ['required', 'array', 'min:1'],
            'quick_percentages.*' => ['numeric', 'between:0,100'],
            'general_preferences' => ['nullable', 'array'],
        ];
    }
}
