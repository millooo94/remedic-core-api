<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlogPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'category_label' => $this->category_label,
            'content_type' => $this->content_type,
            'editorial_category_id' => $this->editorial_category_id,
            'editorial_category_label' => $this->editorialCategory?->name ?? $this->category_label,
            'excerpt' => $this->excerpt,
            'intro_text' => $this->intro_text,
            'cover_image' => $this->cover_image,
            'author_name' => $this->author_name,
            'reviewer_name' => $this->reviewer_name,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_h1' => $this->seo_h1,
            'canonical_url' => $this->canonical_url,
            'robots' => $this->robots?->value ?? $this->robots,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'is_active' => (bool) $this->is_active,
            'published_at' => optional($this->published_at)?->toIso8601String(),
            'is_published' => $this->isPublished(),
            'publication_state' => $this->publicationState()->value,
            'effective_public_visibility' => $this->isPubliclyAvailable(),
            'related_service_ids' => $this->whenLoaded('relatedServices', fn () => $this->relatedServices->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
            'related_article_ids' => $this->whenLoaded('relatedArticles', fn () => $this->relatedArticles->pluck('id')->map(fn ($id) => (int) $id)->values()->all()),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'key' => $section->key,
                'template' => $section->template?->value ?? $section->template ?? 'text',
                'title' => $section->title,
                'subtitle' => $section->subtitle,
                'content' => $section->content,
                'extra_json' => $section->extra_json,
                'image_path' => ($section->template?->value ?? $section->template) === 'image_text'
                    ? ($section->extra_json['image_path'] ?? null)
                    : null,
                'image_url' => ($section->template?->value ?? $section->template) === 'image_text'
                    ? PublicMediaUrl::fromPublicDisk($section->extra_json['image_path'] ?? null, $request)
                    : null,
                'sort_order' => $section->sort_order,
                'is_active' => (bool) $section->is_active,
            ])->values()->all()),
            'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'sort_order' => $faq->sort_order,
                'is_active' => (bool) $faq->is_active,
                'is_structured_data' => (bool) $faq->is_structured_data,
            ])->values()->all()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
