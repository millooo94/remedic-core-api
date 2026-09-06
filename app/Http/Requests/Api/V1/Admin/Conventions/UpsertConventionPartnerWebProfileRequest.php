<?php

namespace App\Http\Requests\Api\V1\Admin\Conventions;

use App\Enums\RobotsValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertConventionPartnerWebProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['prohibited'], 'type' => ['prohibited'], 'logo_path' => ['prohibited'], 'is_active' => ['prohibited'], 'sort_order' => ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'public_slug' => ['required', 'string', 'max:255', Rule::unique('convention_partner_web_profiles', 'public_slug')->ignore($this->route('convention')?->webProfile?->id)],
            'is_web_enabled' => ['required', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string'], 'seo_h1' => ['nullable', 'string', 'max:255'],
            'local_seo_title' => ['nullable', 'string', 'max:255'], 'local_seo_description' => ['nullable', 'string'], 'local_seo_h1' => ['nullable', 'string', 'max:255'], 'is_local_seo_enabled' => ['required', 'boolean'],
            'canonical_url' => ['nullable', 'string', 'max:255', 'in:'], 'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'], 'og_description' => ['nullable', 'string'], 'twitter_title' => ['nullable', 'string', 'max:255'], 'twitter_description' => ['nullable', 'string'],
            'faqs' => ['present', 'array'], 'faqs.*.id' => ['nullable', 'integer'], 'faqs.*.question' => ['required', 'string', 'max:255'], 'faqs.*.answer' => ['required', 'string'], 'faqs.*.is_active' => ['required', 'boolean'], 'faqs.*.is_structured_data' => ['required', 'boolean'],
        ];
    }
}
