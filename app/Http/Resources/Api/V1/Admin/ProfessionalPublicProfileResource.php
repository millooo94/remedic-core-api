<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalPublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $professional = $this->whenLoaded('professional');

        return [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'professional' => $this->whenLoaded('professional', fn () => [
                'id' => $professional->id,
                'full_name' => $professional->full_name,
                'email' => $professional->email,
                'subject_type' => $professional->subject_type?->value ?? $professional->subject_type,
                'is_active' => (bool) $professional->is_active,
            ]),
            'slug' => $this->slug,
            'title_prefix' => $this->title_prefix,
            'registration_number' => $this->registration_number,
            'birth_date' => optional($this->birth_date)?->toDateString(),
            'birth_place' => $this->birth_place,
            'profile_image_path' => $this->profile_image_path,
            'profile_image_url' => PublicMediaUrl::fromPublicDisk($this->profile_image_path, $request),
            'short_bio' => $this->short_bio,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_h1' => $this->seo_h1,
            'canonical_url' => $this->canonical_url,
            'robots' => $this->robots?->value ?? $this->robots,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'degrees' => $this->whenLoaded('professional', fn () => $professional->degrees->map(fn ($degree) => [
                'id' => $degree->id,
                'title' => $degree->title,
                'awarded_on' => optional($degree->awarded_on)?->toDateString(),
                'sort_order' => $degree->sort_order,
            ])->values()->all()),
            'academic_specializations' => $this->whenLoaded('professional', fn () => $professional->academicSpecializations->map(fn ($specialization) => [
                'id' => $specialization->id,
                'title' => $specialization->title,
                'awarded_on' => optional($specialization->awarded_on)?->toDateString(),
                'sort_order' => $specialization->sort_order,
            ])->values()->all()),
            'board_registrations' => $this->whenLoaded('professional', fn () => $professional->boardRegistrations->map(fn ($registration) => [
                'id' => $registration->id,
                'board_name' => $registration->board_name,
                'registered_on' => optional($registration->registered_on)?->toDateString(),
                'sort_order' => $registration->sort_order,
            ])->values()->all()),
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
