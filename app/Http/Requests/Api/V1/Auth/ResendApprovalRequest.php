<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResendApprovalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
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
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'L\'email e obbligatoria.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'email.max' => 'L\'email non puo superare 190 caratteri.',
        ];
    }
}
