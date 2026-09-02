<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WebCheckupIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 15, 20, 25, 50, 100])],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            'is_web_enabled' => ['sometimes', 'nullable', 'boolean'],
            'specialization_id' => ['sometimes', 'nullable', 'integer', Rule::exists('specializations', 'id')],
            'professional_id' => ['sometimes', 'nullable', 'integer', Rule::exists('professionals', 'id')],
            'is_operationally_available' => ['sometimes', 'nullable', 'boolean'],
            'sort' => ['sometimes', 'nullable', Rule::in(['display_name', 'updated_at'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    public function search(): ?string
    {
        $search = trim((string) ($this->validated('q') ?? ''));

        return $search !== '' ? $search : null;
    }

    public function sort(): ?string
    {
        return $this->validated('sort');
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'asc');
    }
}
