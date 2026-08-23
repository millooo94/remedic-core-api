<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecializationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'professional_title_male' => $this->professional_title_male,
            'professional_title_female' => $this->professional_title_female,
            'slug' => $this->slug,
            'color_hex' => $this->color_hex,
            'icon_path' => $this->icon_path,
            'icon_url' => PublicMediaUrl::fromPublicDisk($this->icon_path, $request),
            'featured_image_path' => $this->featured_image_path,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($this->featured_image_path, $request),
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) ($this->sort_order ?? 0),
            'professionals_count' => $this->whenCounted('professionals'),
            'services_count' => $this->whenCounted('services'),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
