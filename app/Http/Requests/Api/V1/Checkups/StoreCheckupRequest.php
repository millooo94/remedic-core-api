<?php

namespace App\Http\Requests\Api\V1\Checkups;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => [
                'required',
                'string',
                'max:190',
                Rule::unique('checkups', 'display_name')->ignore($this->route('checkup')),
            ],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'indicative_duration_minutes' => ['required', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'organizational_notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'professional_id' => ['prohibited'],
            'professional_ids' => ['prohibited'],
            'items.*.professional_id' => ['prohibited'],
            'specialization_id' => ['prohibited'],
            'specialization_ids' => ['prohibited'],
        ];
    }
}
