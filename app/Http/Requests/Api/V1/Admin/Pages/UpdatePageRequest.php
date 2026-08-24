<?php

namespace App\Http\Requests\Api\V1\Admin\Pages;

use App\Enums\PageTemplate;
use App\Enums\RobotsValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $pageId = (int) $this->route('page')->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('pages', 'slug')->ignore($pageId)],
            'template' => ['required', Rule::enum(PageTemplate::class)],
            'excerpt' => ['nullable', 'string'],
            'intro_text' => ['nullable', 'string'],
            'hero_image_path' => ['nullable', 'string', 'max:2048'],
            'hero_image_alt' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'seo_h1' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string'],
            'og_image_path' => ['nullable', 'string', 'max:2048'],
            'twitter_title' => ['nullable', 'string', 'max:255'],
            'twitter_description' => ['nullable', 'string'],
            'twitter_image_path' => ['nullable', 'string', 'max:2048'],
            'meta_author' => ['nullable', 'string', 'max:255'],
            'meta_creator' => ['nullable', 'string', 'max:255'],
            'meta_keywords' => ['nullable', 'string'],
            'faq_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'sections' => ['sometimes', 'array'],
            'sections.*.key' => ['required', 'string', 'max:255', 'distinct'],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.subtitle' => ['nullable', 'string', 'max:255'],
            'sections.*.content' => ['nullable', 'string'],
            'sections.*.extra_json' => ['nullable', 'array'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.is_active' => ['sometimes', 'boolean'],
            'removed_section_keys' => ['sometimes', 'array'],
            'removed_section_keys.*' => ['required', 'string', 'max:255', 'distinct'],
            'faqs' => ['sometimes', 'array'],
            'faqs.*.id' => ['nullable', 'integer'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string'],
            'faqs.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'faqs.*.is_active' => ['sometimes', 'boolean'],
            'faqs.*.is_structured_data' => ['sometimes', 'boolean'],
            'removed_faq_ids' => ['sometimes', 'array'],
            'removed_faq_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
