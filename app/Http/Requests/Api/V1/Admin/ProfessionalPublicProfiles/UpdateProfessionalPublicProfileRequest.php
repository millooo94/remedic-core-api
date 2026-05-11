<?php

namespace App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles;

use App\Enums\RobotsValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfessionalPublicProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $profile = $this->route('professional_public_profile');
        $profileId = (int) $profile->id;

        return [
            'professional_id' => ['required', 'integer', 'exists:professionals,id', Rule::unique('professional_public_profiles', 'professional_id')->ignore($profileId)],
            'slug' => ['required', 'string', 'max:255', Rule::unique('professional_public_profiles', 'slug')->ignore($profileId)],
            'title_prefix' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'profile_image_path' => ['nullable', 'string', 'max:255'],
            'short_bio' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'seo_h1' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string'],
            'degrees' => ['sometimes', 'array'],
            'degrees.*.title' => ['required', 'string', 'max:255'],
            'degrees.*.awarded_on' => ['nullable', 'date'],
            'degrees.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'academic_specializations' => ['sometimes', 'array'],
            'academic_specializations.*.title' => ['required', 'string', 'max:255'],
            'academic_specializations.*.awarded_on' => ['nullable', 'date'],
            'academic_specializations.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'board_registrations' => ['sometimes', 'array'],
            'board_registrations.*.board_name' => ['required', 'string', 'max:255'],
            'board_registrations.*.registered_on' => ['nullable', 'date'],
            'board_registrations.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections' => ['sometimes', 'array'],
            'sections.*.key' => ['required', 'string', 'max:255'],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.subtitle' => ['nullable', 'string', 'max:255'],
            'sections.*.content' => ['nullable', 'string'],
            'sections.*.extra_json' => ['nullable', 'array'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.is_active' => ['sometimes', 'boolean'],
            'faqs' => ['sometimes', 'array'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string'],
            'faqs.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'faqs.*.is_active' => ['sometimes', 'boolean'],
            'faqs.*.is_structured_data' => ['sometimes', 'boolean'],
        ];
    }
}
