<?php

namespace App\Http\Requests\Api\V1\Admin\Pages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadPageImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slot' => ['required_without:page_id', 'nullable', Rule::in(['hero', 'og', 'twitter'])],
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'section_key' => ['required_with:page_id', 'nullable', 'string', 'max:255'],
            'media_slot' => ['required_with:page_id', 'nullable', Rule::in(['image'])],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
