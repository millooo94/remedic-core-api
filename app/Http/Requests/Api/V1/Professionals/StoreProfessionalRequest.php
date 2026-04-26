<?php

namespace App\Http\Requests\Api\V1\Professionals;

use App\Enums\ProfessionalSubjectType;
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
            'subject_type' => ['required', Rule::enum(ProfessionalSubjectType::class)],
            'first_name' => ['nullable', 'required_if:subject_type,individual', 'prohibited_if:subject_type,company', 'string', 'max:120'],
            'last_name' => ['nullable', 'required_if:subject_type,individual', 'prohibited_if:subject_type,company', 'string', 'max:120'],
            'company_name' => ['nullable', 'required_if:subject_type,company', 'prohibited_if:subject_type,individual', 'string', 'max:190'],
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
        $subjectType = strtolower(trim((string) $this->input('subject_type', '')));
        $firstName = $this->normalizeOptionalString($this->input('first_name'));
        $lastName = $this->normalizeOptionalString($this->input('last_name'));
        $companyName = $this->normalizeOptionalString($this->input('company_name'));

        $singleArea = ProfessionalAreaOptions::normalize($this->input('area_name'));
        $rawAreaNames = $this->rawAreaNames();

        $areaNames = collect(is_array($rawAreaNames) ? $rawAreaNames : [])
            ->map(fn ($value) => ProfessionalAreaOptions::normalize((string) $value))
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->values();

        if ($singleArea !== null && $singleArea !== '' && ! $areaNames->contains($singleArea)) {
            $areaNames->prepend($singleArea);
        }

        $this->merge([
            'subject_type' => $subjectType,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => $companyName,
            'area_name' => $areaNames->first() ?? $singleArea,
            'area_names' => $areaNames->all(),
            'iban' => IbanFormatter::normalize($this->input('iban')),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function rawAreaNames(): array
    {
        $rawAreaNames = $this->input('area_names', $this->input('area_names[]', []));

        if (is_string($rawAreaNames)) {
            $decoded = json_decode($rawAreaNames, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return [$rawAreaNames];
        }

        return is_array($rawAreaNames) ? $rawAreaNames : [];
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
