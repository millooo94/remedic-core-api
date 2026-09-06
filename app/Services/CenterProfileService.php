<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CenterProfileService
{
    private const CENTER_FIELDS = [
        'clinic_name', 'legal_company_name', 'business_type', 'vat_number', 'tax_code',
        'clinic_phone', 'whatsapp_number', 'clinic_email', 'pec_email', 'privacy_email',
        'clinic_address', 'clinic_street_name', 'clinic_street_number', 'clinic_postal_code',
        'clinic_city', 'clinic_province', 'clinic_region', 'clinic_country_name', 'clinic_country',
        'google_place_id', 'latitude', 'longitude', 'google_maps_url', 'opening_hours', 'timezone',
        'facebook_url', 'instagram_url', 'tiktok_url', 'youtube_url', 'linkedin_url', 'primary_city', 'primary_area',
        'served_areas', 'served_territory', 'area_served_text', 'google_review_url',
        'parking_label', 'parking_address', 'parking_street_name', 'parking_street_number',
        'parking_postal_code', 'parking_city', 'parking_province', 'parking_region',
        'parking_country_name', 'parking_country', 'parking_google_place_id',
        'parking_latitude', 'parking_longitude', 'parking_description',
    ];

    public function current(): SiteSetting
    {
        return SiteSetting::current();
    }

    public function update(array $payload): SiteSetting
    {
        $flat = [
            ...Arr::only($payload['identity'] ?? [], ['clinic_name', 'legal_company_name', 'business_type', 'vat_number', 'tax_code']),
            'clinic_phone' => data_get($payload, 'contacts.phone'),
            'whatsapp_number' => data_get($payload, 'contacts.whatsapp_number'),
            'clinic_email' => data_get($payload, 'contacts.email'),
            'pec_email' => data_get($payload, 'contacts.pec_email'),
            'privacy_email' => data_get($payload, 'contacts.privacy_email'),
            'clinic_address' => data_get($payload, 'address.formatted_address'),
            'clinic_street_name' => data_get($payload, 'address.street_name'),
            'clinic_street_number' => data_get($payload, 'address.street_number'),
            'clinic_postal_code' => data_get($payload, 'address.postal_code'),
            'clinic_city' => data_get($payload, 'address.city'),
            'clinic_province' => data_get($payload, 'address.province'),
            'clinic_region' => data_get($payload, 'address.region'),
            'clinic_country_name' => data_get($payload, 'address.country'),
            'clinic_country' => data_get($payload, 'address.country_code'),
            'google_place_id' => data_get($payload, 'address.google_place_id'),
            'latitude' => data_get($payload, 'address.latitude'),
            'longitude' => data_get($payload, 'address.longitude'),
            'google_maps_url' => data_get($payload, 'address.google_maps_url'),
            'opening_hours' => $payload['opening_hours'] ?? null,
            'timezone' => data_get($payload, 'opening_hours.timezone', 'Europe/Rome'),
            ...Arr::only($payload['social'] ?? [], ['facebook_url', 'instagram_url', 'tiktok_url', 'youtube_url', 'linkedin_url']),
            ...Arr::only($payload['territory'] ?? [], ['primary_city', 'primary_area', 'served_areas', 'served_territory', 'area_served_text']),
            'google_review_url' => data_get($payload, 'links.google_review_url'),
            'parking_label' => data_get($payload, 'parking.label'),
            'parking_address' => data_get($payload, 'parking.formatted_address', data_get($payload, 'parking.address')),
            'parking_street_name' => data_get($payload, 'parking.street_name'),
            'parking_street_number' => data_get($payload, 'parking.street_number'),
            'parking_postal_code' => data_get($payload, 'parking.postal_code'),
            'parking_city' => data_get($payload, 'parking.city'),
            'parking_province' => data_get($payload, 'parking.province'),
            'parking_region' => data_get($payload, 'parking.region'),
            'parking_country_name' => data_get($payload, 'parking.country'),
            'parking_country' => data_get($payload, 'parking.country_code'),
            'parking_google_place_id' => data_get($payload, 'parking.google_place_id'),
            'parking_latitude' => data_get($payload, 'parking.latitude'),
            'parking_longitude' => data_get($payload, 'parking.longitude'),
            'parking_description' => data_get($payload, 'parking.description'),
        ];

        $flat = Arr::only($flat, self::CENTER_FIELDS);
        foreach ($flat as $key => $value) {
            if (is_string($value)) {
                $flat[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        $flat['clinic_country'] = strtoupper((string) ($flat['clinic_country'] ?? 'IT'));
        $flat['parking_country'] = strtoupper((string) ($flat['parking_country'] ?? '')) ?: null;
        foreach (['vat_number', 'tax_code'] as $field) {
            if (filled($flat[$field] ?? null)) {
                $flat[$field] = Str::upper(preg_replace('/\s+/', '', (string) $flat[$field]));
            }
        }

        return DB::transaction(function () use ($flat): SiteSetting {
            $settings = SiteSetting::ensureSingleton();
            $settings->fill($flat)->save();

            return $settings->refresh();
        });
    }

    public function replaceLogo(UploadedFile $logo): SiteSetting
    {
        $newPath = $logo->store('center/logo', 'public');
        try {
            [$settings, $oldPath] = DB::transaction(function () use ($newPath): array {
                $settings = SiteSetting::ensureSingleton();
                $oldPath = $settings->logo_path;
                $settings->logo_path = $newPath;
                $settings->save();

                return [$settings->refresh(), $oldPath];
            });
            if ($this->isManagedLogoPath($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            return $settings;
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($newPath);
            throw $exception;
        }
    }

    public function removeLogo(): SiteSetting
    {
        [$settings, $oldPath] = DB::transaction(function (): array {
            $settings = SiteSetting::ensureSingleton();
            $oldPath = $settings->logo_path;
            $settings->logo_path = null;
            $settings->save();

            return [$settings->refresh(), $oldPath];
        });
        if ($this->isManagedLogoPath($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return $settings;
    }

    private function isManagedLogoPath(?string $path): bool
    {
        return filled($path)
            && str_starts_with((string) $path, 'center/logo/')
            && ! str_contains((string) $path, '..');
    }
}
