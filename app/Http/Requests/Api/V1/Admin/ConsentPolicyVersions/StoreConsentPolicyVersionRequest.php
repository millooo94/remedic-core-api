<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentPolicyVersions;

use Illuminate\Foundation\Http\FormRequest;

class StoreConsentPolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:255', 'unique:consent_policy_versions,version'],
            'banner_title' => ['nullable', 'string', 'max:255'],
            'banner_text' => ['nullable', 'string'],
            'preferences_title' => ['nullable', 'string', 'max:255'],
            'preferences_text' => ['nullable', 'string'],
            'policy_page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'cookie_policy_page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'privacy_policy_page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'published_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'requires_reconsent' => ['sometimes', 'boolean'],
        ];
    }
}
