<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentCategories;

use App\Enums\ConsentCategoryKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsentCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = (int) $this->route('consent_category')->id;

        return [
            'key' => ['required', Rule::enum(ConsentCategoryKey::class), Rule::unique('consent_categories', 'key')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_state' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
