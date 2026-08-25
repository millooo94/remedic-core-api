<?php

namespace App\Http\Requests\Api\V1\Admin\Services;

use App\Enums\RobotsValue;
use App\Models\Service;
use App\Support\Services\ServiceSectionDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertServiceWebProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Service $service */
        $service = $this->route('service');
        $profileId = $service->webProfile?->id;

        return [
            'canonical_name' => ['prohibited'],
            'display_name' => ['prohibited'],
            'importo_prestazione' => ['prohibited'],
            'default_duration_minutes' => ['prohibited'],
            'featured_image_path' => ['prohibited'],
            'icon_path' => ['prohibited'],
            'is_active' => ['prohibited'],
            'category_id' => ['prohibited'],
            'slug' => ['prohibited'],
            'notes' => ['prohibited'],
            'professional_ids' => ['prohibited'],
            'specialization_ids' => ['prohibited'],
            'price_text' => ['prohibited'],
            'duration_text' => ['prohibited'],
            'social_image_path' => ['prohibited'],
            'og_image_path' => ['prohibited'],
            'is_featured' => ['prohibited'],
            'is_diagnostic' => ['sometimes', 'boolean'],
            'is_aesthetic_medicine' => ['sometimes', 'boolean'],
            'aesthetic_category' => ['nullable', Rule::in(['face_proportions', 'skin_quality', 'redness_dyschromia', 'body'])],
            'is_visit' => ['prohibited'],
            'is_bookable_online' => ['prohibited'],
            'booking' => ['prohibited'],
            'booking_url' => ['prohibited'],
            'service_family_id' => ['prohibited'],
            'family_id' => ['prohibited'],
            'public_slug' => ['required', 'string', 'max:255', Rule::unique('service_web_profiles', 'public_slug')->ignore($profileId)],
            'short_description' => ['nullable', 'string'],
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
            'sections' => ['required', 'array', 'size:'.count(ServiceSectionDefinition::DEFINITIONS)],
            'sections.*' => ['required', 'array'],
            'sections.*.key' => ['required', 'string', 'distinct', Rule::in(ServiceSectionDefinition::keys())],
            'sections.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.intro' => ['nullable', 'string'],
            'sections.*.is_active' => ['required', 'boolean'],
            'sections.*.data' => ['nullable', 'array'],
            'sections.*.data.items' => ['sometimes', 'array'],
            'sections.*.data.items.*' => ['array'],
            'sections.*.data.items.*.title' => ['nullable', 'string', 'max:255'],
            'sections.*.data.items.*.description' => ['nullable', 'string'],
            'sections.*.data.items.*.text' => ['nullable', 'string', 'max:1000'],
            'sections.*.data.bottom_note' => ['nullable', 'string'],
            'sections.*.data.groups' => ['sometimes', 'array'],
            'sections.*.data.groups.*' => ['array'],
            'sections.*.data.groups.*.title' => ['required_with:sections.*.data.groups', 'string', 'max:255'],
            'sections.*.data.groups.*.items' => ['present_with:sections.*.data.groups', 'array'],
            'sections.*.data.groups.*.items.*' => ['array'],
            'sections.*.data.groups.*.items.*.text' => ['required', 'string', 'max:1000'],
            'sections.*.data.steps' => ['sometimes', 'array'],
            'sections.*.data.steps.*' => ['array'],
            'sections.*.data.steps.*.icon_key' => ['required', 'string', Rule::in(ServiceSectionDefinition::ICON_KEYS)],
            'sections.*.data.steps.*.title' => ['required', 'string', 'max:255'],
            'sections.*.data.steps.*.description' => ['nullable', 'string'],
            'sections.*.data.additional_info_enabled' => ['sometimes', 'boolean'],
            'sections.*.data.additional_info_title' => ['nullable', 'string', 'max:255'],
            'sections.*.data.additional_info_text' => ['nullable', 'string'],
            'sections.*.data.additional_info_items' => ['sometimes', 'array'],
            'sections.*.data.additional_info_items.*' => ['array'],
            'sections.*.data.additional_info_items.*.text' => ['required', 'string', 'max:1000'],
            'sections.*.data.info_box_enabled' => ['sometimes', 'boolean'],
            'sections.*.data.info_box_text' => ['nullable', 'string'],
            'faqs' => ['present', 'array'],
            'faqs.*' => ['array'],
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
            /** @var Service $service */
            $service = $this->route('service');
            $isAesthetic = $this->has('is_aesthetic_medicine') ? $this->boolean('is_aesthetic_medicine') : (bool) $service->webProfile?->is_aesthetic_medicine;
            if ($this->filled('aesthetic_category') && ! $isAesthetic) {
                $validator->errors()->add('aesthetic_category', 'La categoria Ã¨ disponibile solo per una Prestazione di medicina estetica.');
            }
            $sections = collect($this->input('sections', []))
                ->filter(fn (mixed $section): bool => is_array($section))
                ->keyBy('key');
            if ($sections->keys()->sort()->values()->all() !== collect(ServiceSectionDefinition::keys())->sort()->values()->all()) {
                $validator->errors()->add('sections', 'Invia esattamente le otto sezioni canoniche della Prestazione.');
            }

            foreach ($sections as $key => $section) {
                $data = is_array($section['data'] ?? null) ? $section['data'] : [];
                $allowed = match ($key) {
                    'what_is' => ['items', 'bottom_note'],
                    'when_to_request' => ['groups'],
                    'procedure' => ['steps', 'additional_info_enabled', 'additional_info_title', 'additional_info_text', 'additional_info_items'],
                    'preparation' => ['items', 'info_box_enabled', 'info_box_text'],
                    default => [],
                };
                if (array_diff(array_keys($data), $allowed) !== []) {
                    $validator->errors()->add("sections.{$key}.data", 'La sezione contiene campi non previsti dal suo editor tipizzato.');
                }

                $index = collect($this->input('sections', []))->search(
                    fn (array $candidate): bool => ($candidate['key'] ?? null) === $key
                );
                $prefix = 'sections.'.($index === false ? $key : $index).'.data';

                if ($key === 'what_is') {
                    foreach ($data['items'] ?? [] as $itemIndex => $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        if (trim((string) ($item['title'] ?? '')) === '') {
                            $validator->errors()->add("{$prefix}.items.{$itemIndex}.title", 'Il titolo è obbligatorio.');
                        }
                        if (array_diff(array_keys($item), ['title', 'description']) !== []) {
                            $validator->errors()->add("{$prefix}.items.{$itemIndex}", 'La voce contiene campi non previsti.');
                        }
                    }
                }

                if ($key === 'when_to_request') {
                    foreach ($data['groups'] ?? [] as $groupIndex => $group) {
                        if (! is_array($group)) {
                            continue;
                        }
                        if (array_diff(array_keys($group), ['title', 'items']) !== []) {
                            $validator->errors()->add("{$prefix}.groups.{$groupIndex}", 'Il gruppo contiene campi non previsti.');
                        }
                        foreach ($group['items'] ?? [] as $itemIndex => $item) {
                            if (! is_array($item)) {
                                continue;
                            }
                            if (array_keys($item) !== ['text']) {
                                $validator->errors()->add("{$prefix}.groups.{$groupIndex}.items.{$itemIndex}", 'La voce contiene solo il testo.');
                            }
                        }
                    }
                }

                if ($key === 'procedure') {
                    foreach ($data['steps'] ?? [] as $stepIndex => $step) {
                        if (! is_array($step)) {
                            continue;
                        }
                        if (array_diff(array_keys($step), ['icon_key', 'title', 'description']) !== []) {
                            $validator->errors()->add("{$prefix}.steps.{$stepIndex}", 'Lo step contiene campi non previsti.');
                        }
                    }
                    foreach ($data['additional_info_items'] ?? [] as $itemIndex => $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        if (array_keys($item) !== ['text']) {
                            $validator->errors()->add("{$prefix}.additional_info_items.{$itemIndex}", 'La voce contiene solo il testo.');
                        }
                    }
                }

                if ($key === 'preparation') {
                    foreach ($data['items'] ?? [] as $itemIndex => $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        if (array_keys($item) !== ['text']) {
                            $validator->errors()->add("{$prefix}.items.{$itemIndex}", 'La voce contiene solo il testo.');
                        }
                    }
                }
            }
        }];
    }
}
