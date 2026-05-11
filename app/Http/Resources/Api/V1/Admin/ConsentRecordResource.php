<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'consent_uuid' => $this->consent_uuid,
            'consent_policy_version_id' => $this->consent_policy_version_id,
            'policy_version' => $this->whenLoaded('policyVersion', fn () => $this->policyVersion ? [
                'id' => $this->policyVersion->id,
                'version' => $this->policyVersion->version,
            ] : null),
            'locale' => $this->locale,
            'source' => $this->source,
            'necessary' => (bool) $this->necessary,
            'preferences' => (bool) $this->preferences,
            'analytics' => (bool) $this->analytics,
            'marketing' => (bool) $this->marketing,
            'status' => $this->status(),
            'consented_at' => optional($this->consented_at)?->toIso8601String(),
            'withdrawn_at' => optional($this->withdrawn_at)?->toIso8601String(),
            'rejected_at' => optional($this->rejected_at)?->toIso8601String(),
            'user_agent' => $this->user_agent,
            'ip_hash' => $this->ip_hash,
            'consent_version_snapshot' => $this->consent_version_snapshot,
            'changes_count' => $this->whenLoaded('changes', fn () => $this->changes->count()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
