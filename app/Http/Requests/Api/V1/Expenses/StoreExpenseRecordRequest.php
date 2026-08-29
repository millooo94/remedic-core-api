<?php

namespace App\Http\Requests\Api\V1\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRecordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_string($amount)) {
            $this->merge([
                'amount' => str_replace(',', '.', trim($amount)),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'expense_template_id' => ['nullable', 'exists:expense_templates,id'],
            'expense_date' => ['required', 'date'],
            'competence_start_date' => ['nullable', 'date'],
            'competence_end_date' => ['nullable', 'date', 'after_or_equal:competence_start_date'],
            'competence_month' => ['nullable', 'integer', 'between:1,12'],
            'competence_year' => ['nullable', 'integer', 'between:2020,2100'],
            'description' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:fixed,variable'],
            'nature' => ['nullable', 'in:ordinary,special'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'supplier' => ['nullable', 'string', 'max:190'],
            'payment_status' => ['nullable', 'in:da_pagare,pagata'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
