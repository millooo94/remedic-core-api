<?php

namespace App\Services;

use App\Models\SiteSetting;

/** Safe, single-purpose public projection of the Centre data used by Contact. */
class ContactCenterDataResolver
{
    /** @return array<string, mixed> */
    public function resolve(SiteSetting $settings): array
    {
        $mapsUrl = $settings->google_maps_url ?: $settings->maps_url;
        $parking = filled($settings->parking_address) ? [
            'label' => $settings->parking_label,
            'address' => $settings->parking_address,
            'description' => $settings->parking_description,
        ] : null;

        return [
            'address' => [
                'formatted_address' => $settings->clinic_address,
                'street_name' => $settings->clinic_street_name,
                'street_number' => $settings->clinic_street_number,
                'postal_code' => $settings->clinic_postal_code,
                'city' => $settings->clinic_city,
                'province' => $settings->clinic_province,
                'region' => $settings->clinic_region,
                'country' => $settings->clinic_country_name,
                'country_code' => $settings->clinic_country,
            ],
            'phone' => $settings->clinic_phone,
            'email' => $settings->clinic_email,
            'opening_hours' => is_array($settings->opening_hours) ? $settings->opening_hours : [],
            'latitude' => $settings->latitude === null ? null : (float) $settings->latitude,
            'longitude' => $settings->longitude === null ? null : (float) $settings->longitude,
            'maps_url' => $mapsUrl,
            'directions_href' => $mapsUrl,
            'parking' => $parking,
        ];
    }
}
