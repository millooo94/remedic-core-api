<?php

namespace App\Http\Requests\Api\V1\PerformanceRecords;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class PerformanceRecordUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_name' => ['nullable', 'string', 'max:190'],
            'area_name' => ['nullable', 'string', 'max:120'],
            'performed_at' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,card'],
            'calculation_mode' => ['required', 'in:percentage,fixed'],
            'percentage_value' => ['nullable', 'numeric', 'between:0,100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'is_invoiced' => ['nullable', 'boolean'],
            'is_black' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mode = $this->input('calculation_mode');
                $quantity = (float) $this->input('quantity', 0);
                $unitAmount = (float) $this->input('unit_amount', 0);
                $totalAmount = round($quantity * $unitAmount, 2);

                if ($mode === 'percentage' && $this->input('percentage_value') === null) {
                    $validator->errors()->add('percentage_value', 'La percentuale e obbligatoria.');
                }

                if ($mode === 'fixed') {
                    $fixed = $this->input('fixed_amount');

                    if ($fixed === null) {
                        $validator->errors()->add('fixed_amount', 'L\'importo forfettario e obbligatorio.');
                    }

                    if ($fixed !== null && (float) $fixed > $totalAmount) {
                        $validator->errors()->add('fixed_amount', 'L\'importo forfettario non puo superare l\'importo prestazione.');
                    }
                }

                if ((int) $this->input('service_id', 0) === 0 && blank($this->input('service_name'))) {
                    $validator->errors()->add('service_name', 'Se non selezioni una prestazione dal catalogo devi indicarne il nome.');
                }
            },
        ];
    }
}
