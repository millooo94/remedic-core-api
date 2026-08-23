<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_url' => $this->site_url,
            'default_meta_title' => $this->default_meta_title,
            'default_meta_description' => $this->default_meta_description,
            'default_og_image_path' => $this->default_og_image_path,
            'default_locality_phrase' => $this->default_locality_phrase,
            'center' => [
                'clinic_name' => $this->clinic_name ?: $this->brand_name ?: $this->site_name,
                'clinic_phone' => $this->clinic_phone,
                'clinic_email' => $this->clinic_email,
                'formatted_address' => $this->clinic_address,
                'city' => $this->clinic_city,
                'logo_url' => PublicMediaUrl::fromPublicDisk($this->logo_path, $request),
            ],
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
