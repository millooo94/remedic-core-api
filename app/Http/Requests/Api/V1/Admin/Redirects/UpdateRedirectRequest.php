<?php

namespace App\Http\Requests\Api\V1\Admin\Redirects;

use App\Models\Redirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $redirectId = (int) $this->route('redirect')->id;

        return [
            'from_path' => ['required', 'string', 'max:255', Rule::unique('redirects', 'from_path')->ignore($redirectId)],
            'to_path' => ['required', 'string', 'max:255'],
            'http_code' => ['required', 'integer', Rule::in([301, 302])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $source = (string) $this->input('from_path');
        $this->merge([
            'from_path' => Redirect::normalizePathValue($source),
            'to_path' => Redirect::normalizeTargetValue((string) $this->input('to_path')),
            '_redirect_source_invalid' => trim($source) === '' || str_contains($source, '?') || str_contains($source, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', trim($source)),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $destination = (string) $this->input('to_path');
            if ($this->boolean('_redirect_source_invalid') || (string) $this->input('from_path') === '/') {
                $validator->errors()->add('from_path', 'Inserisci un percorso sorgente pubblico valido.');
            }
            if ($destination === '/' || preg_match('#(^|/)admin(/|$)|(^|/)api(/|$)#i', $destination)
                || preg_match('#^(javascript|data|file):#i', $destination)) {
                $validator->errors()->add('to_path', 'Inserisci una destinazione pubblica valida.');
            }
        });
    }
}
