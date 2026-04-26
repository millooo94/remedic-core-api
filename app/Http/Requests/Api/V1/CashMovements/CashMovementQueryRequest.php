<?php

namespace App\Http\Requests\Api\V1\CashMovements;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashMovementQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'cash_box_type' => ['nullable', Rule::in(['fatturati', 'black'])],
            'movement_type' => ['nullable', Rule::in(['versamento', 'prelievo'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', Rule::in([
                'movement_date',
                '-movement_date',
                'movement_type',
                '-movement_type',
                'cash_box_type',
                '-cash_box_type',
                'counterparty_name',
                '-counterparty_name',
                'amount',
                '-amount',
                'balance_after',
                '-balance_after',
            ])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
