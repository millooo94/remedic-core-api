<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackofficeIndexRequest extends FormRequest
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
            'sort' => ['sometimes', 'nullable', 'string', 'max:120'],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
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
        $sort = trim((string) ($this->validated('sort') ?? ''));

        return $sort !== '' ? $sort : null;
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'asc');
    }
}
