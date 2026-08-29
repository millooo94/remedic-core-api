<?php

namespace App\Http\Requests\Api\V1\Expenses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRecordQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['fixed', 'variable'])],
            'nature' => ['nullable', Rule::in(['ordinary', 'special'])],
            'expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'payment_status' => ['nullable', Rule::in(['da_pagare', 'pagata'])],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'competence_date_from' => ['nullable', 'date'],
            'competence_date_to' => ['nullable', 'date', 'after_or_equal:competence_date_from'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', Rule::in([
                'expense_date',
                '-expense_date',
                'competence_start_date',
                '-competence_start_date',
                'description',
                '-description',
                'amount',
                '-amount',
                'type',
                '-type',
                'payment_status',
                '-payment_status',
            ])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
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
