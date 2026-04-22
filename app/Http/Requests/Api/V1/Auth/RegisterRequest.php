<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'last_name' => trim((string) $this->input('last_name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'password_confirmation' => (string) $this->input('password_confirmation'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Il nome e obbligatorio.',
            'name.max' => 'Il nome non puo superare 120 caratteri.',
            'last_name.required' => 'Il cognome e obbligatorio.',
            'last_name.max' => 'Il cognome non puo superare 120 caratteri.',
            'email.required' => 'L\'email e obbligatoria.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'email.max' => 'L\'email non puo superare 190 caratteri.',
            'email.unique' => 'Questa email e gia registrata.',
            'password.required' => 'La password e obbligatoria.',
            'password.min' => 'La password deve contenere almeno 8 caratteri.',
            'password_confirmation.required' => 'Conferma la password.',
            'password_confirmation.same' => 'Password e conferma password non coincidono.',
        ];
    }
}
