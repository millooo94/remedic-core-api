<?php

namespace App\Http\Requests\Api\V1\Specializations;

use App\Models\Specialization;
use Illuminate\Validation\Rule;

class UpdateSpecializationRequest extends StoreSpecializationRequest
{
    public function rules(): array
    {
        /** @var Specialization|null $specialization */
        $specialization = $this->route('specialization');

        return [
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', Rule::unique('specializations', 'slug')->ignore($specialization?->id)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
