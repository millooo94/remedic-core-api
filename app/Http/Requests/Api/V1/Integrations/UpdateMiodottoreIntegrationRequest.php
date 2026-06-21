<?php

namespace App\Http\Requests\Api\V1\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMiodottoreIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => $this->normalizeString($this->input('username')),
            'password' => $this->normalizeString($this->input('password')),
            'notes' => $this->normalizeString($this->input('notes')),
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
