<?php

namespace App\Http\Requests\Api\V1\Admin\BlogPosts;

use App\Enums\EditorialSectionTemplate;
use App\Enums\RobotsValue;
use App\Models\BlogPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:blog_posts,slug'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'category_label' => ['nullable', 'string', 'max:255'],
            'content_type' => ['nullable', Rule::in(['health_pill', 'news'])],
            'editorial_category' => ['nullable', 'string', 'max:64'],
            'excerpt' => ['nullable', 'string'],
            'intro_text' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'string', 'max:255'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'reviewer_name' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'seo_h1' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'sections' => ['sometimes', 'array'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.key' => ['nullable', 'string', 'max:255', 'distinct'],
            'sections.*.template' => ['nullable', Rule::enum(EditorialSectionTemplate::class)],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.subtitle' => ['nullable', 'string', 'max:255'],
            'sections.*.content' => ['required', 'string'],
            'sections.*.image_path' => ['nullable', 'string', 'max:255'],
            'sections.*.extra_json' => ['nullable', 'array'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.is_active' => ['sometimes', 'boolean'],
            'faqs' => ['sometimes', 'array'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string'],
            'faqs.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'faqs.*.is_active' => ['sometimes', 'boolean'],
            'faqs.*.is_structured_data' => ['sometimes', 'boolean'],
            'related_service_ids' => ['sometimes', 'array'],
            'related_service_ids.*' => ['required', 'integer', 'distinct', 'exists:services,id'],
            'related_article_ids' => ['sometimes', 'array'],
            'related_article_ids.*' => ['required', 'integer', 'distinct', 'exists:blog_posts,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $category = $this->input('editorial_category');
            if ($category !== null && ! array_key_exists($category, BlogPost::editorialCategories($this->input('content_type')))) {
                $validator->errors()->add('editorial_category', 'La categoria editoriale non è valida per il tipo contenuto selezionato.');
            }
            foreach ($this->input('sections', []) as $index => $section) {
                $template = $section['template'] ?? EditorialSectionTemplate::Text->value;
                $imagePath = $section['image_path'] ?? data_get($section, 'extra_json.image_path');
                if ($template === EditorialSectionTemplate::ImageText->value && ! filled($imagePath)) {
                    $validator->errors()->add("sections.$index.image_path", 'Il template con immagine richiede un media associato.');
                }
            }
        }];
    }
}
