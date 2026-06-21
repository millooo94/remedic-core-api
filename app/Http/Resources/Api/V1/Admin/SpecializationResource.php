<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecializationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color_hex' => $this->color_hex,
            'short_description' => $this->short_description,
            'intro_text' => $this->intro_text,
            'local_intro_text' => $this->local_intro_text,
            'local_area_notes' => $this->local_area_notes,
            'seo_title' => $this->seo_title,
            'local_seo_title' => $this->local_seo_title,
            'seo_description' => $this->seo_description,
            'local_seo_description' => $this->local_seo_description,
            'seo_h1' => $this->seo_h1,
            'local_seo_h1' => $this->local_seo_h1,
            'canonical_url' => $this->canonical_url,
            'robots' => $this->robots?->value ?? $this->robots,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'is_local_seo_enabled' => (bool) $this->is_local_seo_enabled,
            'is_active' => (bool) $this->is_active,
            'is_web_active' => (bool) $this->is_web_active,
            'sort_order' => (int) $this->sort_order,
            'services_count' => $this->whenCounted('services'),
            'professionals_count' => $this->whenCounted('professionals'),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'key' => $section->key,
                'title' => $section->title,
                'subtitle' => $section->subtitle,
                'content' => $section->content,
                'extra_json' => $section->extra_json,
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
