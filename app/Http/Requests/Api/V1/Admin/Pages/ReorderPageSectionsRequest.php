<?php

namespace App\Http\Requests\Api\V1\Admin\Pages;

use Illuminate\Foundation\Http\FormRequest;

class ReorderPageSectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_keys' => ['required', 'array', 'min:1'],
            'section_keys.*' => ['required', 'string', 'max:255', 'distinct'],
        ];
    }
}
