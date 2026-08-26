<?php

namespace App\Http\Requests\Api\V1\Management\CenterSettings;

use App\Rules\ValidOpeningHours;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCenterSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $nullableString = ['nullable', 'string', 'max:255'];
        $url = ['nullable', 'url:http,https', 'max:2048'];

        return [
            'identity' => ['required', 'array'],
            'identity.clinic_name' => ['required', 'string', 'max:255'],
            'identity.legal_company_name' => $nullableString,
            'identity.business_type' => ['nullable', Rule::in(['MedicalBusiness', 'MedicalClinic'])],
            'identity.vat_number' => ['nullable', 'string', 'max:32'],
            'identity.tax_code' => ['nullable', 'string', 'max:32'],
            'contacts' => ['required', 'array'],
            'contacts.phone' => $nullableString,
            'contacts.whatsapp_number' => $nullableString,
            'contacts.email' => ['nullable', 'email', 'max:255'],
            'contacts.pec_email' => ['nullable', 'email', 'max:255'],
            'contacts.privacy_email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'array'],
            'address.formatted_address' => $nullableString,
            'address.street_name' => $nullableString,
            'address.street_number' => ['nullable', 'string', 'max:32'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.city' => $nullableString,
            'address.province' => ['nullable', 'string', 'max:100'],
            'address.region' => ['nullable', 'string', 'max:100'],
            'address.country' => ['nullable', 'string', 'max:100'],
            'address.country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'address.google_place_id' => $nullableString,
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address.google_maps_url' => $url,
            'opening_hours' => ['required', 'array', new ValidOpeningHours],
            'social' => ['required', 'array'],
            'social.facebook_url' => $url,
            'social.instagram_url' => $url,
            'social.tiktok_url' => $url,
            'social.youtube_url' => $url,
            'social.linkedin_url' => $url,
            'territory' => ['required', 'array'],
            'territory.primary_city' => $nullableString,
            'territory.primary_area' => $nullableString,
            'territory.served_areas' => ['nullable', 'array'],
            'territory.served_areas.*' => ['string', 'max:255', 'distinct'],
            'territory.served_territory' => $nullableString,
            'territory.area_served_text' => ['nullable', 'string', 'max:5000'],
            'links' => ['required', 'array'],
            'links.google_review_url' => $url,
            'parking' => ['nullable', 'array'],
            'parking.label' => $nullableString,
            'parking.address' => $nullableString,
            'parking.description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
