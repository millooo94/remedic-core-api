<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $avatarUrl = PublicMediaUrl::fromPublicDisk($this->avatar_path, $request);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'is_active' => $this->is_active,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'is_email_verified' => $this->hasVerifiedEmail(),
            'avatar_url' => $avatarUrl,
            'last_login_at' => optional($this->last_login_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
