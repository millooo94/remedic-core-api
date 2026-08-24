<?php

namespace App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles;

use App\Models\ProfessionalPublicProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfessionalPublicProfileRequest extends FormRequest
{
    use EquipeProfileRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var ProfessionalPublicProfile $profile */
        $profile = $this->route('professionalPublicProfile')
            ?? $this->route('professional_public_profile')
            ?? $this->route('equipe');

        return [
            'professional_id' => ['prohibited'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('professional_public_profiles', 'slug')->ignore($profile->id)],
            ...$this->contentRules(),
        ];
    }
}
