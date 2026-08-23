<?php

namespace App\Http\Requests\Api\V1\Management\CenterSettings;

use Illuminate\Foundation\Http\FormRequest;

class UploadCenterLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
    }
}
