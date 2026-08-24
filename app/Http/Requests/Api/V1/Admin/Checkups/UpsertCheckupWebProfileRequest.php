<?php

namespace App\Http\Requests\Api\V1\Admin\Checkups;

use App\Enums\RobotsValue;
use App\Models\Checkup;
use App\Support\Checkups\CheckupSectionDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertCheckupWebProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Checkup $checkup */
        $checkup = $this->route('checkup');
        $profileId = $checkup->webProfile?->id;

        return [
            'display_name' => ['prohibited'], 'price_amount' => ['prohibited'],
            'indicative_duration_minutes' => ['prohibited'], 'is_active' => ['prohibited'],
            'organizational_notes' => ['prohibited'], 'featured_image_path' => ['prohibited'],
            'icon_path' => ['prohibited'], 'items' => ['prohibited'], 'service_ids' => ['prohibited'],
            'professional_ids' => ['prohibited'], 'specialization_ids' => ['prohibited'],
            'price_text' => ['prohibited'], 'duration_text' => ['prohibited'],
            'booking' => ['prohibited'], 'booking_url' => ['prohibited'], 'is_featured' => ['prohibited'],
            'public_slug' => ['required', 'string', 'max:255', Rule::unique('checkup_web_profiles', 'public_slug')->ignore($profileId)],
            'short_description' => ['nullable', 'string'],
            'category_label' => ['nullable', 'string', 'max:255'],
            'is_web_enabled' => ['required', 'boolean'],
            'list_sort_order' => ['required', 'integer', 'min:0'],
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
            'sections' => ['required', 'array', 'size:'.count(CheckupSectionDefinition::DEFINITIONS)],
            'sections.*' => ['required', 'array'],
            'sections.*.key' => ['required', 'string', 'distinct', Rule::in(CheckupSectionDefinition::keys())],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.intro' => ['nullable', 'string'],
            'sections.*.is_active' => ['required', 'boolean'],
            'sections.*.data' => ['nullable', 'array'],
            'sections.*.data.items' => ['sometimes', 'array'],
            'sections.*.data.items.*' => ['array'],
            'sections.*.data.items.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.data.items.*.description' => ['nullable', 'string'],
            'sections.*.data.items.*.text' => ['nullable', 'string', 'max:1000'],
            'sections.*.data.groups' => ['sometimes', 'array'],
            'sections.*.data.groups.*.title' => ['required', 'string', 'max:255'],
            'sections.*.data.groups.*.items' => ['present', 'array'],
            'sections.*.data.groups.*.items.*.text' => ['required', 'string', 'max:1000'],
            'sections.*.data.steps' => ['sometimes', 'array'],
            'sections.*.data.steps.*.icon_key' => ['required', 'string', Rule::in(CheckupSectionDefinition::ICON_KEYS)],
            'sections.*.data.steps.*.title' => ['required', 'string', 'max:255'],
            'sections.*.data.steps.*.description' => ['nullable', 'string'],
            'faqs' => ['present', 'array'],
            'faqs.*' => ['array'], 'faqs.*.id' => ['nullable', 'integer'],
            'faqs.*.question' => ['required', 'string', 'max:255'],
            'faqs.*.answer' => ['required', 'string'],
            'faqs.*.is_active' => ['required', 'boolean'],
            'faqs.*.is_structured_data' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = collect($this->input('sections', []))->filter(fn ($section) => is_array($section));
            $sections = $input->keyBy('key');
            if ($sections->keys()->sort()->values()->all() !== collect(CheckupSectionDefinition::keys())->sort()->values()->all()) {
                $validator->errors()->add('sections', 'Invia esattamente le dieci sezioni canoniche del Check-up.');
            }

            foreach ($input->values() as $index => $section) {
                $key = $section['key'] ?? '';
                $data = is_array($section['data'] ?? null) ? $section['data'] : [];
                $allowed = match ($key) {
                    'what_is', 'preparation' => ['items'],
                    'target' => ['groups'],
                    'procedure' => ['steps'],
                    default => [],
                };
                if (array_diff(array_keys($data), $allowed) !== []) {
                    $validator->errors()->add("sections.{$index}.data", 'La sezione contiene campi non previsti dal suo editor tipizzato.');
                }
                if ($key === 'what_is') {
                    foreach ($data['items'] ?? [] as $itemIndex => $item) {
                        if (array_diff(array_keys((array) $item), ['title', 'description']) !== []) {
                            $validator->errors()->add("sections.{$index}.data.items.{$itemIndex}", 'La voce contiene solo titolo e descrizione.');
                        }
                        if (trim((string) ($item['title'] ?? '')) === '') {
                            $validator->errors()->add("sections.{$index}.data.items.{$itemIndex}.title", 'Il titolo è obbligatorio.');
                        }
                    }
                }
                if ($key === 'preparation') {
                    foreach ($data['items'] ?? [] as $itemIndex => $item) {
                        if (array_keys((array) $item) !== ['text']) {
                            $validator->errors()->add("sections.{$index}.data.items.{$itemIndex}", 'La voce contiene solo il testo.');
                        }
                    }
                }
                if ($key === 'target') {
                    foreach ($data['groups'] ?? [] as $groupIndex => $group) {
                        if (array_diff(array_keys((array) $group), ['title', 'items']) !== []) {
                            $validator->errors()->add("sections.{$index}.data.groups.{$groupIndex}", 'Il gruppo contiene solo titolo e voci.');
                        }
                        foreach ($group['items'] ?? [] as $itemIndex => $item) {
                            if (array_keys((array) $item) !== ['text']) {
                                $validator->errors()->add("sections.{$index}.data.groups.{$groupIndex}.items.{$itemIndex}", 'La voce contiene solo il testo.');
                            }
                        }
                    }
                }
                if ($key === 'procedure') {
                    foreach ($data['steps'] ?? [] as $stepIndex => $step) {
                        if (array_diff(array_keys((array) $step), ['icon_key', 'title', 'description']) !== []) {
                            $validator->errors()->add("sections.{$index}.data.steps.{$stepIndex}", 'Lo step contiene solo icona, titolo e descrizione.');
                        }
                    }
                }
            }
        }];
    }
}
