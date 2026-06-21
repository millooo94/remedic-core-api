<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'site_name',
        'site_url',
        'brand_name',
        'default_meta_title',
        'default_meta_description',
        'clinic_name',
        'clinic_phone',
        'clinic_email',
        'clinic_address',
        'clinic_city',
        'primary_city',
        'primary_area',
        'served_areas',
        'province_or_area_served',
        'clinic_region',
        'clinic_postal_code',
        'clinic_country',
        'google_maps_url',
        'google_review_url',
        'google_review_delay_days',
        'google_review_delay_hours',
        'google_review_delay_minutes',
        'google_review_delay_seconds',
        'maps_url',
        'latitude',
        'longitude',
        'area_served_text',
        'default_locality_phrase',
        'facebook_url',
        'instagram_url',
        'linkedin_url',
        'whatsapp_number',
        'logo_path',
        'default_og_image_path',
        'opening_hours',
        'vat_number',
        'legal_company_name',
        'business_type',
        'cmp_enabled',
        'cmp_banner_enabled',
        'cmp_consent_cookie_name',
        'cmp_consent_cookie_ttl_days',
        'cmp_consent_storage_strategy',
        'cmp_show_reject_all_button',
        'cmp_show_accept_all_button',
        'cmp_show_manage_preferences_button',
        'cmp_respect_dnt_flag',
        'cmp_consent_mode_enabled',
        'cmp_auto_reprompt_on_policy_change',
        'cmp_default_locale',
        'privacy_email',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'opening_hours' => 'array',
            'served_areas' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'cmp_enabled' => 'boolean',
            'cmp_banner_enabled' => 'boolean',
            'google_review_delay_days' => 'integer',
            'google_review_delay_hours' => 'integer',
            'google_review_delay_minutes' => 'integer',
            'google_review_delay_seconds' => 'integer',
            'cmp_consent_cookie_ttl_days' => 'integer',
            'cmp_show_reject_all_button' => 'boolean',
            'cmp_show_accept_all_button' => 'boolean',
            'cmp_show_manage_preferences_button' => 'boolean',
            'cmp_respect_dnt_flag' => 'boolean',
            'cmp_consent_mode_enabled' => 'boolean',
            'cmp_auto_reprompt_on_policy_change' => 'boolean',
        ];
    }

    public static function singleton(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            ['clinic_country' => 'IT'],
        );
    }
}
