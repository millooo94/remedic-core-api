<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\CheckupWebProfile;
use App\Support\Checkups\CheckupSectionDefinition;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckupWebProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CheckupWebProfile|null $profile */
        $profile = $this->webProfile;
        $items = $this->items->sortBy(fn ($item) => [$item->sort_order, $item->id])->values();
        $areas = collect();
        $professionals = collect();

        foreach ($items as $item) {
            foreach ($item->service?->specializations ?? [] as $area) {
                $areas->put($area->id, ['id' => (int) $area->id, 'name' => $area->name, 'slug' => $area->slug]);
            }
            foreach ($item->service?->professionalServices ?? [] as $link) {
                if ($link->professional !== null) {
                    $professionals->put($link->professional->id, [
                        'id' => (int) $link->professional->id,
                        'display_name' => trim(implode(' ', array_filter([$link->professional->honorific_prefix, $link->professional->full_name]))),
                    ]);
                }
            }
        }

        $master = [
            'id' => $this->id,
            'name' => $this->display_name,
            'price' => $this->price_amount,
            'duration_minutes' => $this->indicative_duration_minutes,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->trashed(),
            'is_operationally_available' => $this->isOperationallyAvailable(),
            'operationally_active' => (bool) $this->is_active,
            'operationally_available' => $this->isOperationallyAvailable(),
            'featured_image_path' => $this->featured_image_path,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($this->featured_image_path, $request),
            'icon_path' => $this->icon_path,
            'icon_url' => PublicMediaUrl::fromPublicDisk($this->icon_path, $request),
            'items' => $items->map(fn ($item) => [
                'service_id' => (int) $item->service_id,
                'sort_order' => (int) $item->sort_order,
                'name' => $item->service?->publicLabel(),
                'is_active' => (bool) $item->service?->is_active,
                'is_archived' => (bool) $item->service?->trashed(),
                'price' => $item->service?->importo_prestazione,
                'duration_minutes' => $item->service?->default_duration_minutes,
            ])->all(),
            'areas' => $areas->values()->all(),
            'professionals' => $professionals->values()->all(),
            'related_checkups' => $this->whenLoaded('relatedWebCheckups', fn () => $this->relatedWebCheckups->map(fn ($checkup) => [
                'name' => $checkup->display_name,
                'public_slug' => $checkup->webProfile->public_slug,
                'category_label' => $checkup->webProfile->category_label,
            ])->values()->all()),
        ];

        return [
            'id' => $this->id,
            'master' => $master,
            'checkup' => $master,
            'web_profile' => $profile ? $this->profile($profile) : null,
            'is_configured' => $profile !== null,
            'effective_public_visibility' => $this->isEffectivelyVisible(),
            'is_operationally_available' => $this->isOperationallyAvailable(),
            'status' => $profile === null ? 'not_configured' : ($this->isEffectivelyVisible() ? 'published' : 'not_published'),
        ];
    }

    private function profile(CheckupWebProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'checkup_id' => $profile->checkup_id,
            'public_slug' => $profile->public_slug,
            'short_description' => $profile->short_description,
            'category_label' => $profile->category_label,
            'is_web_enabled' => (bool) $profile->is_web_enabled,
            'list_sort_order' => (int) $profile->list_sort_order,
            'seo_title' => $profile->seo_title,
            'local_seo_title' => $profile->local_seo_title,
            'seo_description' => $profile->seo_description,
            'local_seo_description' => $profile->local_seo_description,
            'seo_h1' => $profile->seo_h1,
            'local_seo_h1' => $profile->local_seo_h1,
            'is_local_seo_enabled' => (bool) $profile->is_local_seo_enabled,
            'canonical_url' => $profile->canonical_url,
            'robots' => $profile->robots?->value ?? $profile->robots,
            'og_title' => $profile->og_title,
            'og_description' => $profile->og_description,
            'og_image_path' => $this->featured_image_path,
            'sections' => $profile->sections->whereIn('key', CheckupSectionDefinition::keys())
                ->sortBy(fn ($section) => [$section->sort_order, $section->id])
                ->map(fn ($section) => [
                    'id' => $section->id, 'key' => $section->key,
                    'label' => CheckupSectionDefinition::DEFINITIONS[$section->key],
                    'title' => $section->title, 'intro' => $section->content,
                    'data' => $section->extra_json ?? new \stdClass,
                    'sort_order' => (int) $section->sort_order,
                    'is_active' => (bool) $section->is_active,
                ])->values()->all(),
            'faqs' => $profile->faqs->map(fn ($faq) => [
                'id' => $faq->id, 'question' => $faq->question, 'answer' => $faq->answer,
                'sort_order' => (int) $faq->sort_order, 'is_active' => (bool) $faq->is_active,
                'is_structured_data' => (bool) $faq->is_structured_data,
            ])->values()->all(),
            'created_at' => optional($profile->created_at)?->toIso8601String(),
            'updated_at' => optional($profile->updated_at)?->toIso8601String(),
        ];
    }
}
