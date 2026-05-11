<?php

namespace App\Http\Requests\Api\V1\Admin\Specializations;

use App\Enums\RobotsValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpecializationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:specializations,slug'],
            'short_description' => ['nullable', 'string'],
            'intro_text' => ['nullable', 'string'],
            'local_intro_text' => ['nullable', 'string'],
            'local_area_notes' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'local_seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'local_seo_description' => ['nullable', 'string'],
            'seo_h1' => ['nullable', 'string', 'max:255'],
            'local_seo_h1' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string'],
            'is_local_seo_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'sections' => ['sometimes', 'array'],
            'sections.*.key' => ['required', 'string', 'max:255'],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.subtitle' => ['nullable', 'string', 'max:255'],
            'sections.*.content' => ['nullable', 'string'],
            'sections.*.extra_json' => ['nullable', 'array'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.is_active' => ['sometimes', 'boolean'],
            'faqs' => ['sometimes', 'array'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string'],
            'faqs.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'faqs.*.is_active' => ['sometimes', 'boolean'],
            'faqs.*.is_structured_data' => ['sometimes', 'boolean'],
        ];
    }
}
