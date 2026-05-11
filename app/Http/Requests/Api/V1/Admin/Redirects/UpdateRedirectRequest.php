<?php

namespace App\Http\Requests\Api\V1\Admin\Redirects;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $redirectId = (int) $this->route('redirect')->id;

        return [
            'from_path' => ['required', 'string', 'max:255', Rule::unique('redirects', 'from_path')->ignore($redirectId)],
            'to_path' => ['required', 'string', 'max:255'],
            'http_code' => ['required', 'integer', Rule::in([301, 302, 307, 308])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
