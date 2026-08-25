<?php

namespace App\Http\Resources\Api\V1\Management;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CenterSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hours = is_array($this->opening_hours) ? $this->opening_hours : null;

        return [
            'identity' => [
                'clinic_name' => $this->clinic_name ?: $this->brand_name ?: $this->site_name,
                'legal_company_name' => $this->legal_company_name,
                'business_type' => $this->business_type,
                'vat_number' => $this->vat_number,
                'tax_code' => $this->tax_code,
                'logo_path' => $this->logo_path,
                'logo_url' => PublicMediaUrl::fromPublicDisk($this->logo_path, $request),
            ],
            'contacts' => [
                'phone' => $this->clinic_phone,
                'whatsapp_number' => $this->whatsapp_number,
                'email' => $this->clinic_email,
                'pec_email' => $this->pec_email,
                'privacy_email' => $this->privacy_email,
            ],
            'address' => [
                'formatted_address' => $this->clinic_address,
                'street_name' => $this->clinic_street_name,
                'street_number' => $this->clinic_street_number,
                'postal_code' => $this->clinic_postal_code,
                'city' => $this->clinic_city,
                'province' => $this->clinic_province,
                'region' => $this->clinic_region,
                'country' => $this->clinic_country_name,
                'country_code' => $this->clinic_country,
                'google_place_id' => $this->google_place_id,
                'latitude' => $this->latitude === null ? null : (float) $this->latitude,
                'longitude' => $this->longitude === null ? null : (float) $this->longitude,
                'google_maps_url' => $this->google_maps_url ?: $this->maps_url,
            ],
            'opening_hours' => $this->canonicalHours($hours),
            'opening_hours_legacy' => $hours !== null && ! $this->isCanonicalHours($hours),
            'social' => [
                'facebook_url' => $this->facebook_url,
                'instagram_url' => $this->instagram_url,
                'linkedin_url' => $this->linkedin_url,
            ],
            'territory' => [
                'primary_city' => $this->primary_city,
                'primary_area' => $this->primary_area,
                'served_areas' => is_array($this->served_areas) ? $this->served_areas : [],
                'served_territory' => $this->served_territory ?: $this->province_or_area_served,
                'area_served_text' => $this->area_served_text,
            ],
            'links' => ['google_review_url' => $this->google_review_url],
            'parking' => [
                'label' => $this->parking_label,
                'address' => $this->parking_address,
                'description' => $this->parking_description,
            ],
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function canonicalHours(?array $hours): array
    {
        if ($hours !== null && $this->isCanonicalHours($hours)) {
            return $hours;
        }

        return [
            'version' => 1,
            'timezone' => $this->timezone ?: 'Europe/Rome',
            'days' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], []),
        ];
    }

    private function isCanonicalHours(array $hours): bool
    {
        return ($hours['version'] ?? null) === 1 && is_array($hours['days'] ?? null);
    }
}
