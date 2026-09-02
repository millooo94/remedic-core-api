<?php

namespace App\Http\Requests\Api\V1\Admin\MedicalAreas;

use App\Enums\RobotsValue;
use App\Models\Specialization;
use App\Support\MedicalAreas\MedicalAreaSectionDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertMedicalAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Specialization $specialization */
        $specialization = $this->route('specialization');
        $profileId = $specialization->webProfile?->id;

        return [
            'name' => ['prohibited'],
            'professional_title_male' => ['prohibited'],
            'professional_title_female' => ['prohibited'],
            'color_hex' => ['prohibited'],
            'is_active' => ['prohibited'],
            'sort_order' => ['prohibited'],
            'icon_path' => ['prohibited'],
            'featured_image_path' => ['prohibited'],
            'service_ids' => ['prohibited'],
            'professional_ids' => ['prohibited'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('specialization_web_profiles', 'slug')->ignore($profileId)],
            'short_description' => ['nullable', 'string'],
            'is_web_enabled' => ['required', 'boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'local_seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'local_seo_description' => ['nullable', 'string'],
            'seo_h1' => ['nullable', 'string', 'max:255'],
            'local_seo_h1' => ['nullable', 'string', 'max:255'],
            'is_local_seo_enabled' => ['required', 'boolean'],
            'canonical_url' => ['nullable', 'string', 'max:255'],
            'robots' => ['nullable', Rule::enum(RobotsValue::class)],
            'og_title' => ['nullable', 'string', 'max:255'],
            'og_description' => ['nullable', 'string'],
            'sections' => ['required', 'array', 'size:'.count(MedicalAreaSectionDefinition::DEFINITIONS)],
            'sections.*.key' => ['required', 'string', 'distinct', Rule::in(MedicalAreaSectionDefinition::keys())],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.intro' => ['nullable', 'string'],
            'sections.*.is_active' => ['required', 'boolean'],
            'sections.*.data' => ['nullable', 'array'],
            'sections.*.data.service_ids' => ['prohibited'],
            'sections.*.data.professional_ids' => ['prohibited'],
            'sections.*.data.items' => ['sometimes', 'array'],
            'sections.*.data.items.*.icon_key' => ['nullable', 'string', Rule::in(MedicalAreaSectionDefinition::ICON_KEYS)],
            'sections.*.data.items.*.eyebrow' => ['nullable', 'string', 'max:100'],
            'sections.*.data.items.*.title' => ['required_with:sections.*.data.items', 'string', 'max:255'],
            'sections.*.data.items.*.description' => ['nullable', 'string'],
            'sections.*.data.steps' => ['sometimes', 'array'],
            'sections.*.data.steps.*.icon_key' => ['nullable', 'string', Rule::in(MedicalAreaSectionDefinition::ICON_KEYS)],
            'sections.*.data.steps.*.title' => ['required_with:sections.*.data.steps', 'string', 'max:255'],
            'sections.*.data.steps.*.description' => ['nullable', 'string'],
            'sections.*.data.bottom_note' => ['nullable', 'string'],
            'sections.*.data.alert_enabled' => ['sometimes', 'boolean'],
            'sections.*.data.alert_title' => ['nullable', 'string', 'max:255'],
            'sections.*.data.alert_text' => ['nullable', 'string'],
            'sections.*.data.appointment_preparation_enabled' => ['sometimes', 'boolean'],
            'sections.*.data.appointment_preparation_label' => ['nullable', 'string', 'max:255'],
            'sections.*.data.appointment_preparation_items' => ['sometimes', 'array'],
            'sections.*.data.appointment_preparation_items.*' => ['string', 'max:500'],
            'faqs' => ['present', 'array'],
            'faqs.*.id' => ['nullable', 'integer'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string'],
            'faqs.*.is_active' => ['required', 'boolean'],
            'faqs.*.is_structured_data' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $sections = collect($this->input('sections', []))->keyBy('key');
            if ($sections->keys()->sort()->values()->all() !== collect(MedicalAreaSectionDefinition::keys())->sort()->values()->all()) {
                $validator->errors()->add('sections', 'Invia esattamente le sette sezioni canoniche dell’Area medica.');
            }

            foreach ($sections as $key => $section) {
                $data = $section['data'] ?? [];
                $allowed = match ($key) {
                    'scope' => ['items', 'bottom_note'],
                    'when_useful' => ['items', 'alert_enabled', 'alert_title', 'alert_text'],
                    'visit_process' => ['steps', 'appointment_preparation_enabled', 'appointment_preparation_label', 'appointment_preparation_items'],
                    default => [],
                };
                if (array_diff(array_keys($data), $allowed) !== []) {
                    $validator->errors()->add("sections.{$key}.data", 'La sezione contiene campi non previsti dal suo editor tipizzato.');
                }
            }
        }];
    }
}
