<?php

namespace App\Http\Requests\Api\V1\Applications;

use App\Enums\SupportedLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'application_type' => ['required', 'string', 'max:100', Rule::exists('application_types', 'key')->where('is_active', true)],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
            'locale' => ['nullable', Rule::enum(SupportedLocale::class)],
            'privacy_consent' => ['accepted'],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'max:5120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['first_name', 'last_name', 'email', 'phone', 'message', 'application_type'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }
}
