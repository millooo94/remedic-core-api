<?php

namespace App\Http\Requests\Api\V1\CashMovements;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'movement_date' => ['required', 'date'],
            'movement_type' => ['required', 'in:versamento,prelievo'],
            'cash_box_type' => ['required', 'in:fatturati,black'],
            'counterparty_name' => ['nullable', 'string', 'max:190'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
