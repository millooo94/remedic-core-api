<?php

namespace App\Http\Requests\Api\V1\Admin\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class MarkAllNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['context' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]{0,99}$/']];
    }
}
