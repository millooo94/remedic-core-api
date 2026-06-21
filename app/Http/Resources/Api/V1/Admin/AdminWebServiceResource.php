<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminWebServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'canonical_name' => $this->canonical_name,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'intro_text' => $this->intro_text,
            'local_intro_text' => $this->local_intro_text,
            'local_area_notes' => $this->local_area_notes,
            'preparation_notes' => $this->preparation_notes,
            'duration_text' => $this->duration_text,
            'price_text' => $this->price_text,
            'exam_report_time' => $this->exam_report_time,
            'featured_image_path' => $this->featured_image_path,
            'social_image_path' => $this->social_image_path,
            'default_duration_minutes' => $this->default_duration_minutes,
            'importo_prestazione' => $this->importo_prestazione,
            'is_diagnostic' => (bool) $this->is_diagnostic,
            'is_visit' => (bool) $this->is_visit,
            'is_featured' => (bool) $this->is_featured,
            'is_local_seo_enabled' => (bool) $this->is_local_seo_enabled,
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
            'is_active' => (bool) $this->is_active,
            'is_web_active' => (bool) $this->is_web_active,
            'sort_order' => (int) $this->sort_order,
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
