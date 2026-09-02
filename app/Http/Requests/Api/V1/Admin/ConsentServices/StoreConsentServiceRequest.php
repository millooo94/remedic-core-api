<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentServices;

use App\Enums\ConsentExecutionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreConsentServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consent_category_id' => ['required', 'integer', 'exists:consent_categories,id'],
            'key' => ['required', 'string', 'max:255', 'unique:consent_services,key'],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'purpose' => ['nullable', 'string'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'cookie_names' => ['sometimes', 'array'],
            'cookie_names.*' => ['string', 'max:255'],
            'retention_period' => ['nullable', 'string', 'max:255'],
            'legal_basis_hint' => ['nullable', 'string', 'max:255'],
            'execution_mode' => ['required', Rule::enum(ConsentExecutionMode::class)],
            'public_config' => ['sometimes', 'array'],
            'public_config.driver' => ['nullable', 'string', Rule::in(['ga4', 'gtm', 'meta_pixel', 'custom'])],
            'public_config.measurement_id' => ['nullable', 'string', 'regex:/^G-[A-Z0-9]+$/'],
            'public_config.container_id' => ['nullable', 'string', 'regex:/^GTM-[A-Z0-9]+$/'],
            'public_config.pixel_id' => ['nullable', 'string', 'regex:/^\d+$/'],
            'public_config.src' => ['nullable', 'url', 'max:2048'],
            'public_config.position' => ['nullable', 'string', Rule::in(['head', 'body'])],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $config = $this->input('public_config', []);
            if (! is_array($config)) {
                return;
            }
            $driver = $config['driver'] ?? null;
            $required = match ($driver) {
                'ga4' => 'measurement_id', 'gtm' => 'container_id', 'meta_pixel' => 'pixel_id', 'custom' => 'src', default => null
            };
            if ($required !== null && (! is_string($config[$required] ?? null) || $config[$required] === '')) {
                $validator->errors()->add("public_config.$required", 'Configurazione del provider obbligatoria.');
            }
        });
    }
}
