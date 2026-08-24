<?php

namespace App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles;

use App\Support\Professionals\EquipeSectionDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEquipeSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'size:'.count(EquipeSectionDefinition::DEFINITIONS)],
            'sections.*.key' => ['required', 'string', 'distinct', Rule::in(EquipeSectionDefinition::keys())],
            'sections.*.is_active' => ['required', 'boolean'],
        ];
    }
}
