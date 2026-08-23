<?php

namespace App\Http\Requests\Api\V1\Admin\SiteSettings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSiteSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_url' => ['nullable', 'url', 'max:255'],
            'default_meta_title' => ['nullable', 'string', 'max:255'],
            'default_meta_description' => ['nullable', 'string'],
            'default_locality_phrase' => ['nullable', 'string', 'max:255'],
            'default_og_image_path' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
