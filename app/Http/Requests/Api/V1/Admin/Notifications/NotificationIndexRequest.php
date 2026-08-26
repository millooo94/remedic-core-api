<?php

namespace App\Http\Requests\Api\V1\Admin\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'string', Rule::in(['all', 'unread'])],
            'context' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]{0,99}$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
