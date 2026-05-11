<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentServices;

use App\Enums\ConsentExecutionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsentServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consent_category_id' => ['required', 'integer', 'exists:consent_categories,id'],
            'key' => ['required', 'string', 'max:255', 'unique:consent_services,key'],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'purpose' => ['nullable', 'string'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'cookie_names' => ['sometimes', 'array'],
            'cookie_names.*' => ['string', 'max:255'],
            'retention_period' => ['nullable', 'string', 'max:255'],
            'legal_basis_hint' => ['nullable', 'string', 'max:255'],
            'execution_mode' => ['required', Rule::enum(ConsentExecutionMode::class)],
            'public_config' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
