<?php

namespace App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles;

use App\Enums\RobotsValue;
use App\Enums\ScientificContributionType;
use Illuminate\Validation\Rule;

trait EquipeProfileRules
{
    protected function contentRules(): array
    {
        $iconRule = ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'];

        return [
            'short_bio' => ['nullable', 'string', 'max:1000'],
            'bio_content' => ['nullable', 'string'],
            'approach_content' => ['nullable', 'string'],
            'is_web_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['prohibited'],
            'subject_type' => ['prohibited'],
            'gender' => ['prohibited'],
            'honorific_prefix' => ['prohibited'],
            'first_name' => ['prohibited'],
            'last_name' => ['prohibited'],
            'company_name' => ['prohibited'],
            'full_name' => ['prohibited'],
            'title_prefix' => ['prohibited'],
            'avatar' => ['prohibited'],
            'avatar_path' => ['prohibited'],
            'registration_number' => ['prohibited'],
            'birth_date' => ['prohibited'],
            'birth_place' => ['prohibited'],
            'profile_image_path' => ['prohibited'],
            'area_name' => ['prohibited'],
            'area_names' => ['prohibited'],
            'email' => ['prohibited'],
            'iban' => ['prohibited'],
            'notes' => ['prohibited'],
            'specialization_ids' => ['prohibited'],
            'services' => ['prohibited'],
            'service_ids' => ['prohibited'],
            'professional_services' => ['prohibited'],
            'degrees' => ['prohibited'],
            'academic_specializations' => ['prohibited'],
            'board_registrations' => ['prohibited'],
            'career_experiences' => ['prohibited'],
            'faqs' => ['prohibited'],
            'sections' => ['prohibited'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'seo_h1' => ['nullable', 'string', 'max:255'],
            'local_seo_title' => ['nullable', 'string', 'max:255'],
            'local_seo_description' => ['nullable', 'string'],
            'local_seo_h1' => ['nullable', 'string', 'max:255'],
            'is_local_seo_enabled' => ['sometimes', 'boolean'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string'],
            'hero_competency_ids' => ['sometimes', 'array', 'max:3'],
            'hero_competency_ids.*' => ['required', 'integer', 'distinct', 'exists:professional_profile_competencies,id'],
            'approach_principles' => ['sometimes', 'array'],
            'approach_principles.*.id' => ['nullable', 'integer', 'exists:professional_profile_approach_principles,id'],
            'approach_principles.*.label' => ['required', 'string', 'max:120'],
            'approach_principles.*.icon_key' => $iconRule,
            'approach_principles.*.is_active' => ['sometimes', 'boolean'],
            'competencies' => ['sometimes', 'array'],
            'competencies.*.id' => ['nullable', 'integer', 'exists:professional_profile_competencies,id'],
            'competencies.*.title' => ['required', 'string', 'max:160'],
            'competencies.*.description' => ['nullable', 'string', 'max:2000'],
            'competencies.*.icon_key' => $iconRule,
            'competencies.*.is_active' => ['sometimes', 'boolean'],
            'scientific_activities' => ['sometimes', 'array'],
            'scientific_activities.*.id' => ['nullable', 'integer', 'exists:professional_profile_scientific_activities,id'],
            'scientific_activities.*.contribution_type' => ['required', Rule::enum(ScientificContributionType::class)],
            'scientific_activities.*.occurred_on' => ['nullable', 'date'],
            'scientific_activities.*.year' => ['nullable', 'integer', 'between:1900,2100'],
            'scientific_activities.*.title' => ['required', 'string', 'max:255'],
            'scientific_activities.*.source' => ['nullable', 'string', 'max:255'],
            'scientific_activities.*.short_description' => ['nullable', 'string', 'max:2000'],
            'scientific_activities.*.url' => ['nullable', 'url', 'max:2048'],
            'scientific_activities.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
