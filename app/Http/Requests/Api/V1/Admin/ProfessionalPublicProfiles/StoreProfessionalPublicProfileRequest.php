<?php

namespace App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfessionalPublicProfileRequest extends FormRequest
{
    use EquipeProfileRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professional_id' => ['required', 'integer', 'exists:professionals,id', 'unique:professional_public_profiles,professional_id'],
            'slug' => ['required', 'string', 'max:255', 'unique:professional_public_profiles,slug'],
            ...$this->contentRules(),
        ];
    }
}
