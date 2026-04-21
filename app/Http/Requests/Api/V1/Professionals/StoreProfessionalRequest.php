<?php

namespace App\Http\Requests\Api\V1\Professionals;

use App\Support\Professionals\IbanFormatter;
use App\Support\Professionals\ProfessionalAreaOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'area_name' => ['nullable', 'string', Rule::in(ProfessionalAreaOptions::values())],
            'area_names' => ['required', 'array', 'min:1'],
            'area_names.*' => ['required', 'string', Rule::in(ProfessionalAreaOptions::values())],
            'email' => ['nullable', 'email', 'max:190'],
            'iban' => ['nullable', 'string', 'min:15', 'max:34', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $singleArea = ProfessionalAreaOptions::normalize($this->input('area_name'));
        $rawAreaNames = $this->input('area_names', []);

        $areaNames = collect(is_array($rawAreaNames) ? $rawAreaNames : [])
            ->map(fn ($value) => ProfessionalAreaOptions::normalize((string) $value))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->values();

        if ($singleArea !== null && $singleArea !== '' && ! $areaNames->contains($singleArea)) {
            $areaNames->prepend($singleArea);
        }

        $this->merge([
            'area_name' => $areaNames->first() ?? $singleArea,
            'area_names' => $areaNames->all(),
            'iban' => IbanFormatter::normalize($this->input('iban')),
        ]);
    }
}
