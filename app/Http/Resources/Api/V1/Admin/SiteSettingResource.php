<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_name' => $this->site_name,
            'site_url' => $this->site_url,
            'brand_name' => $this->brand_name,
            'default_meta_title' => $this->default_meta_title,
            'default_meta_description' => $this->default_meta_description,
            'clinic_name' => $this->clinic_name,
            'clinic_phone' => $this->clinic_phone,
            'clinic_email' => $this->clinic_email,
            'clinic_address' => $this->clinic_address,
            'clinic_city' => $this->clinic_city,
            'primary_city' => $this->primary_city,
            'primary_area' => $this->primary_area,
            'served_areas' => $this->served_areas,
            'province_or_area_served' => $this->province_or_area_served,
            'clinic_region' => $this->clinic_region,
            'clinic_postal_code' => $this->clinic_postal_code,
            'clinic_country' => $this->clinic_country,
            'google_maps_url' => $this->google_maps_url,
            'google_review_url' => $this->google_review_url,
            'maps_url' => $this->maps_url,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'area_served_text' => $this->area_served_text,
            'default_locality_phrase' => $this->default_locality_phrase,
            'facebook_url' => $this->facebook_url,
            'instagram_url' => $this->instagram_url,
            'linkedin_url' => $this->linkedin_url,
            'whatsapp_number' => $this->whatsapp_number,
            'logo_path' => $this->logo_path,
            'default_og_image_path' => $this->default_og_image_path,
            'opening_hours' => $this->opening_hours,
            'vat_number' => $this->vat_number,
            'legal_company_name' => $this->legal_company_name,
            'business_type' => $this->business_type,
            'cmp_enabled' => (bool) $this->cmp_enabled,
            'cmp_banner_enabled' => (bool) $this->cmp_banner_enabled,
            'cmp_consent_cookie_name' => $this->cmp_consent_cookie_name,
            'cmp_consent_cookie_ttl_days' => $this->cmp_consent_cookie_ttl_days,
            'cmp_consent_storage_strategy' => $this->cmp_consent_storage_strategy,
            'cmp_show_reject_all_button' => (bool) $this->cmp_show_reject_all_button,
            'cmp_show_accept_all_button' => (bool) $this->cmp_show_accept_all_button,
            'cmp_show_manage_preferences_button' => (bool) $this->cmp_show_manage_preferences_button,
            'cmp_respect_dnt_flag' => (bool) $this->cmp_respect_dnt_flag,
            'cmp_consent_mode_enabled' => (bool) $this->cmp_consent_mode_enabled,
            'cmp_auto_reprompt_on_policy_change' => (bool) $this->cmp_auto_reprompt_on_policy_change,
            'cmp_default_locale' => $this->cmp_default_locale,
            'privacy_email' => $this->privacy_email,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
