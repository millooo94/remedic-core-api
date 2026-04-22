<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'device_name' => ($deviceName = trim((string) $this->input('device_name'))) !== '' ? $deviceName : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'L\'email e obbligatoria.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'email.max' => 'L\'email non puo superare 190 caratteri.',
            'password.required' => 'La password e obbligatoria.',
            'password.min' => 'La password deve contenere almeno 8 caratteri.',
            'device_name.max' => 'Il nome dispositivo non puo superare 100 caratteri.',
        ];
    }
}
