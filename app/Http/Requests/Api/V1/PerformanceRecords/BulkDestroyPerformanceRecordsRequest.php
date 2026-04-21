<?php

namespace App\Http\Requests\Api\V1\PerformanceRecords;

use Illuminate\Foundation\Http\FormRequest;

class BulkDestroyPerformanceRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:performance_records,id'],
        ];
    }
}
