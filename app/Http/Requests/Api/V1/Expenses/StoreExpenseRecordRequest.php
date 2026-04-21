<?php

namespace App\Http\Requests\Api\V1\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRecordRequest extends FormRequest
{
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
            'competence_month' => ['nullable', 'integer', 'between:1,12'],
            'competence_year' => ['nullable', 'integer', 'between:2020,2100'],
            'description' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:fixed,variable'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'supplier' => ['nullable', 'string', 'max:190'],
            'payment_status' => ['nullable', 'in:da_pagare,pagata'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
