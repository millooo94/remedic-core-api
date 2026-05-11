<?php

namespace App\Http\Requests\Api\V1\Admin\Redirects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_path' => ['required', 'string', 'max:255', 'unique:redirects,from_path'],
            'to_path' => ['required', 'string', 'max:255'],
            'http_code' => ['required', 'integer', Rule::in([301, 302, 307, 308])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
