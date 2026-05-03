<?php

namespace App\Http\Requests\Api\V1\PerformanceRecords;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PerformanceRecordQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'only_unreconciled' => ['nullable', 'boolean'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'area_name' => ['nullable', 'string', 'max:150'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', 'string', Rule::in([
                'performed_at',
                '-performed_at',
                'professional_name_snapshot',
                '-professional_name_snapshot',
                'category_name_snapshot',
                '-category_name_snapshot',
                'service_name_snapshot',
                '-service_name_snapshot',
                'quantity',
                '-quantity',
                'total_amount',
                '-total_amount',
                'professional_amount',
                '-professional_amount',
                'center_amount',
                '-center_amount',
                'payment_status',
                '-payment_status',
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
