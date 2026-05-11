<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentPolicyVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'banner_title' => $this->banner_title,
            'banner_text' => $this->banner_text,
            'preferences_title' => $this->preferences_title,
            'preferences_text' => $this->preferences_text,
            'policy_page_id' => $this->policy_page_id,
            'cookie_policy_page_id' => $this->cookie_policy_page_id,
            'privacy_policy_page_id' => $this->privacy_policy_page_id,
            'policy_page' => $this->whenLoaded('policyPage', fn () => $this->policyPage ? ['id' => $this->policyPage->id, 'title' => $this->policyPage->title] : null),
            'cookie_policy_page' => $this->whenLoaded('cookiePolicyPage', fn () => $this->cookiePolicyPage ? ['id' => $this->cookiePolicyPage->id, 'title' => $this->cookiePolicyPage->title] : null),
            'privacy_policy_page' => $this->whenLoaded('privacyPolicyPage', fn () => $this->privacyPolicyPage ? ['id' => $this->privacyPolicyPage->id, 'title' => $this->privacyPolicyPage->title] : null),
            'is_active' => (bool) $this->is_active,
            'published_at' => optional($this->published_at)?->toIso8601String(),
            'requires_reconsent' => (bool) $this->requires_reconsent,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
