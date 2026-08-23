<?php

namespace App\Services;

use App\Models\SiteSetting;

class CenterCoordinatesProvider
{
    /** @return array{lat: ?float, lng: ?float} */
    public function coordinates(): array
    {
        $settings = SiteSetting::current();
        $lat = $this->number($settings->latitude);
        $lng = $this->number($settings->longitude);

        if ($lat !== null && $lng !== null) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        // Transitional fallback for deployments not yet configured through Management.
        return [
            'lat' => $this->number(config('services.geocoding.remedic_lat')),
            'lng' => $this->number(config('services.geocoding.remedic_lng')),
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
