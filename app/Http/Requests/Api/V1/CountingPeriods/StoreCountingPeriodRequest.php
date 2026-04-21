<?php

namespace App\Http\Requests\Api\V1\CountingPeriods;

use Illuminate\Foundation\Http\FormRequest;

class StoreCountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:190'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'is_closed' => ['sometimes', 'boolean'],
        ];
    }
}
