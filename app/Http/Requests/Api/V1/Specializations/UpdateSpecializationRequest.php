<?php

namespace App\Http\Requests\Api\V1\Specializations;

use App\Models\Specialization;
use Illuminate\Validation\Rule;

class UpdateSpecializationRequest extends StoreSpecializationRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        /** @var Specialization|null $specialization */
        $specialization = $this->route('specialization');
        if ($specialization !== null) {
            $this->merge(['slug' => $specialization->slug]);
        }
    }

    public function rules(): array
    {
        /** @var Specialization|null $specialization */
        $specialization = $this->route('specialization');

        return [
            'name' => ['required', 'string', 'max:190'],
            'short_description' => ['nullable', 'string', 'max:40'],
            'professional_title_male' => ['nullable', 'string', 'max:190'],
            'professional_title_female' => ['nullable', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', Rule::unique('specializations', 'slug')->ignore($specialization?->id)],
            'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_svg' => ['prohibited'],
            'is_active' => ['sometimes', 'boolean'],
            'remove_icon' => ['prohibited'],
            'icon_path' => ['prohibited'],
            'featured_image_path' => ['prohibited'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
