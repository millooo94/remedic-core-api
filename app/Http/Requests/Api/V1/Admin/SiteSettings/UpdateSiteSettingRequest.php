<?php

namespace App\Http\Requests\Api\V1\Admin\SiteSettings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_name' => ['nullable', 'string', 'max:255'],
            'site_url' => ['nullable', 'url', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'default_meta_title' => ['nullable', 'string', 'max:255'],
            'default_meta_description' => ['nullable', 'string'],
            'clinic_name' => ['nullable', 'string', 'max:255'],
            'clinic_phone' => ['nullable', 'string', 'max:255'],
            'clinic_email' => ['nullable', 'email', 'max:255'],
            'clinic_address' => ['nullable', 'string', 'max:255'],
            'clinic_city' => ['nullable', 'string', 'max:255'],
            'primary_city' => ['nullable', 'string', 'max:255'],
            'primary_area' => ['nullable', 'string', 'max:255'],
            'served_areas' => ['nullable', 'array'],
            'served_areas.*' => ['string', 'max:255'],
            'province_or_area_served' => ['nullable', 'string', 'max:255'],
            'clinic_region' => ['nullable', 'string', 'max:255'],
            'clinic_postal_code' => ['nullable', 'string', 'max:255'],
            'clinic_country' => ['nullable', 'string', 'max:2'],
            'google_maps_url' => ['nullable', 'url', 'max:255'],
            'google_review_url' => ['nullable', 'url', 'max:2048'],
            'maps_url' => ['nullable', 'url', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'area_served_text' => ['nullable', 'string'],
            'default_locality_phrase' => ['nullable', 'string', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'default_og_image_path' => ['nullable', 'string', 'max:2048'],
            'opening_hours' => ['nullable', 'array'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'legal_company_name' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:255'],
            'cmp_enabled' => ['sometimes', 'boolean'],
            'cmp_banner_enabled' => ['sometimes', 'boolean'],
            'cmp_consent_cookie_name' => ['nullable', 'string', 'max:255'],
            'cmp_consent_cookie_ttl_days' => ['nullable', 'integer', 'min:1'],
            'cmp_consent_storage_strategy' => ['nullable', 'string', 'max:255'],
            'cmp_show_reject_all_button' => ['sometimes', 'boolean'],
            'cmp_show_accept_all_button' => ['sometimes', 'boolean'],
            'cmp_show_manage_preferences_button' => ['sometimes', 'boolean'],
            'cmp_respect_dnt_flag' => ['sometimes', 'boolean'],
            'cmp_consent_mode_enabled' => ['sometimes', 'boolean'],
            'cmp_auto_reprompt_on_policy_change' => ['sometimes', 'boolean'],
            'cmp_default_locale' => ['nullable', 'string', 'max:12'],
            'privacy_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
