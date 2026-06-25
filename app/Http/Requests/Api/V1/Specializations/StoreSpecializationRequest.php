<?php

namespace App\Http\Requests\Api\V1\Specializations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreSpecializationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'professional_title_male' => ['nullable', 'string', 'max:190'],
            'professional_title_female' => ['nullable', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', Rule::unique('specializations', 'slug')],
            'color_hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_svg' => ['nullable', 'file', 'mimes:svg', 'max:1024'],
            'remove_icon' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name', ''));
        $slug = trim((string) $this->input('slug', ''));

        $this->merge([
            'name' => $name,
            'professional_title_male' => $this->normalizeOptionalString($this->input('professional_title_male')),
            'professional_title_female' => $this->normalizeOptionalString($this->input('professional_title_female')),
            'slug' => Str::slug($slug !== '' ? $slug : $name),
            'color_hex' => $this->normalizeColorHex($this->input('color_hex')),
            'remove_icon' => $this->boolean('remove_icon'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    private function normalizeColorHex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        if ($normalized === '') {
            return null;
        }

        $normalized = str_starts_with($normalized, '#') ? $normalized : "#{$normalized}";

        return preg_match('/^#[0-9A-F]{6}$/', $normalized) === 1 ? $normalized : $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
