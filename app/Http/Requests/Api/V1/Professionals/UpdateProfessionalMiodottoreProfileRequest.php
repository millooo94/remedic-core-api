<?php

namespace App\Http\Requests\Api\V1\Professionals;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfessionalMiodottoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'external_url' => $this->normalizeString($this->input('external_url')),
        ]);
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
