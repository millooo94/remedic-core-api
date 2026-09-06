<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\ConventionPartnerWebProfile;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConventionPartnerWebProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ConventionPartnerWebProfile|null $profile */
        $profile = $this->webProfile;
        $master = [
            'id' => $this->id, 'name' => $this->name, 'type' => $this->type?->value ?? $this->type,
            'type_label' => $this->type?->label(), 'logo_path' => $this->logo_path,
            'logo_url' => PublicMediaUrl::fromPublicDisk($this->logo_path, $request),
            'is_active' => (bool) $this->is_active, 'sort_order' => (int) $this->sort_order,
        ];

        return [
            'id' => $this->id, 'master' => $master, 'convention_partner' => $master,
            'web_profile' => $profile ? [
                'id' => $profile->id, 'convention_partner_id' => $profile->convention_partner_id,
                'title' => $profile->title, 'public_slug' => $profile->public_slug,
                'is_web_enabled' => (bool) $profile->is_web_enabled, 'seo_title' => $profile->seo_title, 'seo_description' => $profile->seo_description,
                'seo_h1' => $profile->seo_h1, 'local_seo_title' => $profile->local_seo_title,
                'local_seo_description' => $profile->local_seo_description, 'local_seo_h1' => $profile->local_seo_h1,
                'is_local_seo_enabled' => (bool) $profile->is_local_seo_enabled, 'canonical_url' => null,
                'robots' => $profile->robots?->value ?? $profile->robots, 'og_title' => $profile->og_title,
                'og_description' => $profile->og_description, 'og_image_path' => $profile->og_image_path, 'og_image_url' => PublicMediaUrl::fromPublicDisk($profile->og_image_path, $request),
                'twitter_title' => $profile->twitter_title, 'twitter_description' => $profile->twitter_description,
                'twitter_image_path' => $profile->twitter_image_path, 'twitter_image_url' => PublicMediaUrl::fromPublicDisk($profile->twitter_image_path, $request), 'sections' => [],
                'faqs' => $profile->faqs->map(fn ($faq) => ['id' => $faq->id, 'question' => $faq->question, 'answer' => $faq->answer, 'is_active' => (bool) $faq->is_active, 'is_structured_data' => (bool) $faq->is_structured_data])->values()->all(),
            ] : null,
            'is_configured' => $profile !== null, 'effective_public_visibility' => false,
            'status' => $profile === null ? 'not_configured' : 'page_not_available',
        ];
    }
}
