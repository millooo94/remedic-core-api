<?php

namespace App\Http\Requests\Api\V1\PerformanceRecords;

use App\Enums\VisitShift;
use App\Support\Numbers\ScaledNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

abstract class PerformanceRecordUpsertRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $patientIds = $this->input('patient_ids');

        if ((! is_array($patientIds) || $patientIds === []) && $this->filled('patient_id')) {
            $patientIds = [$this->input('patient_id')];
        }

        if (is_array($patientIds)) {
            $patientIds = array_values(array_filter(
                $patientIds,
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ));
        }

        $normalizedPayload = [
            'patient_ids' => $patientIds,
            'visit_shift' => $this->input('visit_shift', VisitShift::Morning->value),
        ];

        if ($this->boolean('is_provvigione')) {
            $normalizedPayload['is_black'] = true;
            $normalizedPayload['is_invoiced'] = false;
        }

        $this->merge($normalizedPayload);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'patient_ids' => ['required', 'array', 'min:1'],
            'patient_ids.*' => ['required', 'integer', 'distinct', 'exists:patients,id'],
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'service_name' => ['nullable', 'string', 'max:190'],
            'area_name' => ['nullable', 'string', 'max:120'],
            'performed_at' => ['required', 'date'],
            'visit_shift' => ['required', Rule::enum(VisitShift::class)],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_amount' => ['required'],
            'direct_cost' => ['nullable'],
            'payment_method' => ['required', 'in:cash,card'],
            'payment_status' => ['nullable', 'in:da_pagare,pagata'],
            'split_mode' => ['nullable', 'in:standard,advanced'],
            'calculation_mode' => ['nullable', 'in:percentage,fixed'],
            'percentage_value' => ['nullable', 'numeric', 'between:0,100'],
            'fixed_amount' => ['nullable'],
            'advanced_splits' => ['nullable', 'array', 'min:1'],
            'advanced_splits.*.subject_type' => ['required_with:advanced_splits', 'in:professional,center'],
            'advanced_splits.*.professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'advanced_splits.*.amount' => ['required_with:advanced_splits'],
            'advanced_splits.*.description' => ['nullable', 'string', 'max:190'],
            'is_invoiced' => ['nullable', 'boolean'],
            'is_black' => ['nullable', 'boolean'],
            'is_promo' => ['nullable', 'boolean'],
            'is_provvigione' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mode = (string) $this->input('calculation_mode');
                $splitMode = (string) $this->input('split_mode', 'standard');
                $quantity = (int) $this->input('quantity', 0);
                $patientIds = collect($this->input('patient_ids', []))
                    ->filter(static fn (mixed $value): bool => is_numeric($value))
                    ->map(static fn (mixed $value): int => (int) $value)
                    ->values();
                $unitAmountCents = $this->readWholeAmountForValidator(
                    validator: $validator,
                    field: 'unit_amount',
                    message: 'L\'importo prestazione deve essere un numero intero maggiore di zero.',
                );
                $directCostCents = $this->readWholeAmountForValidator(
                    validator: $validator,
                    field: 'direct_cost',
                    message: 'Il costo diretto deve essere un numero intero senza centesimi.',
                    nullable: true,
                );

                if ($unitAmountCents === null || $directCostCents === null) {
                    return;
                }

                if ($patientIds->count() !== $quantity) {
                    $validator->errors()->add(
                        'patient_ids',
                        sprintf('Il numero di pazienti selezionati deve essere uguale alla quantita (%d).', $quantity),
                    );
                }

                $totalAmountCents = $quantity * $unitAmountCents;
                $netDivisibleAmountCents = $totalAmountCents - $directCostCents;

                if ($unitAmountCents <= 0) {
                    $validator->errors()->add('unit_amount', 'L\'importo prestazione deve essere un numero intero maggiore di zero.');
                }

                if ($directCostCents < 0) {
                    $validator->errors()->add('direct_cost', 'Il costo diretto prestazione deve essere maggiore o uguale a 0.');
                }

                if ($directCostCents > $totalAmountCents) {
                    $validator->errors()->add('direct_cost', 'Il costo diretto prestazione non puo superare l\'importo prestazione.');
                }

                if ($splitMode === 'standard') {
                    if (! in_array($mode, ['percentage', 'fixed'], true)) {
                        $validator->errors()->add('calculation_mode', 'La modalita calcolo e obbligatoria in ripartizione standard.');
                    }

                    if ($mode === 'percentage' && $this->input('percentage_value') === null) {
                        $validator->errors()->add('percentage_value', 'La percentuale e obbligatoria.');
                    }

                    if ($mode === 'fixed') {
                        $fixed = $this->input('fixed_amount');
                        $fixedCents = $this->readWholeAmountForValidator(
                            validator: $validator,
                            field: 'fixed_amount',
                            message: 'L\'importo forfettario deve essere un numero intero senza centesimi.',
                            nullable: true,
                        );

                        if ($fixed === null) {
                            $validator->errors()->add('fixed_amount', 'L\'importo forfettario e obbligatorio.');
                        }

                        if ($fixed !== null && $fixedCents !== null && $fixedCents > $netDivisibleAmountCents) {
                            $validator->errors()->add('fixed_amount', 'L\'importo forfettario non puo superare la base netta da dividere.');
                        }
                    }
                }

                if ($splitMode === 'advanced') {
                    $splits = $this->input('advanced_splits');
                    if (! is_array($splits) || count($splits) === 0) {
                        $validator->errors()->add('advanced_splits', 'In modalita avanzata devi inserire almeno una quota.');
                    } else {
                        $sum = 0;
                        foreach ($splits as $index => $split) {
                            $subjectType = (string) ($split['subject_type'] ?? '');
                            $professionalId = $split['professional_id'] ?? null;
                            $amountCents = $this->readWholeSplitAmountForValidator($validator, $index);

                            if ($subjectType === 'professional' && empty($professionalId)) {
                                $validator->errors()->add("advanced_splits.$index.professional_id", 'Se il tipo soggetto e Professionista, devi selezionare il professionista.');
                            }

                            if ($subjectType === 'center' && ! empty($professionalId)) {
                                $validator->errors()->add("advanced_splits.$index.professional_id", 'Per il soggetto Centro non devi selezionare un professionista.');
                            }

                            if ($amountCents !== null && $amountCents <= 0) {
                                $validator->errors()->add("advanced_splits.$index.amount", 'L\'importo quota deve essere maggiore di zero.');
                            }

                            if ($amountCents !== null) {
                                $sum += $amountCents;
                            }
                        }

                        if (! $validator->errors()->hasAny(array_map(
                            static fn (int $index): string => "advanced_splits.$index.amount",
                            array_keys($splits),
                        )) && abs($sum - $netDivisibleAmountCents) > 0) {
                            $validator->errors()->add(
                                'advanced_splits',
                                sprintf(
                                    'La somma quote (%s) deve essere uguale alla base netta da ripartire (%s).',
                                    ScaledNumber::fromScaledInteger((int) $sum, 2),
                                    ScaledNumber::fromScaledInteger($netDivisibleAmountCents, 2),
                                ),
                            );
                        }
                    }
                }

                if ((int) $this->input('service_id', 0) === 0 && blank($this->input('service_name'))) {
                    $validator->errors()->add('service_name', 'Se non selezioni una prestazione dal catalogo devi indicarne il nome.');
                }

                if (! $this->boolean('is_provvigione') && $this->boolean('is_black') && $this->boolean('is_invoiced')) {
                    $message = 'Una prestazione black non puo essere segnata come fatturata.';

                    $validator->errors()->add('is_black', $message);
                    $validator->errors()->add('is_invoiced', $message);
                }

                if (! $this->boolean('is_provvigione') && $this->boolean('is_black') && (string) $this->input('payment_method') === 'card') {
                    $validator->errors()->add('payment_method', 'Una prestazione black non puo essere registrata con pagamento carta.');
                }

                if (! $this->boolean('is_provvigione') && $this->boolean('is_black') && $this->boolean('is_promo')) {
                    $message = 'Una prestazione non puo essere contemporaneamente black e promo.';

                    $validator->errors()->add('is_black', $message);
                    $validator->errors()->add('is_promo', $message);
                }

                if ($this->boolean('is_provvigione') && $this->boolean('is_invoiced')) {
                    $message = 'Una prestazione in provvigione non puo essere segnata come fatturata da Remedic.';

                    $validator->errors()->add('is_provvigione', $message);
                    $validator->errors()->add('is_invoiced', $message);
                }

                if ($this->boolean('is_provvigione') && (string) $this->input('payment_method') === 'card') {
                    $validator->errors()->add('payment_method', 'Una prestazione in provvigione non puo essere registrata con pagamento carta del centro.');
                }

                if ($this->boolean('is_provvigione') && $this->boolean('is_promo')) {
                    $message = 'Una prestazione non puo essere contemporaneamente promo e provvigione.';

                    $validator->errors()->add('is_provvigione', $message);
                    $validator->errors()->add('is_promo', $message);
                }
            },
        ];
    }

    private function readWholeAmountForValidator(
        Validator $validator,
        string $field,
        string $message,
        bool $nullable = false,
    ): ?int
    {
        $value = $this->input($field);

        if ($nullable && ($value === null || $value === '')) {
            return 0;
        }

        try {
            return ScaledNumber::assertWholeAmount($value, $field, $message);
        } catch (ValidationException) {
            $validator->errors()->add($field, $message);

            return null;
        }
    }

    private function readWholeSplitAmountForValidator(Validator $validator, int $index): ?int
    {
        $field = "advanced_splits.$index.amount";

        try {
            return ScaledNumber::assertWholeAmount(
                $this->input($field),
                $field,
                'L\'importo quota deve essere un numero intero senza centesimi.',
            );
        } catch (ValidationException) {
            $validator->errors()->add($field, 'L\'importo quota deve essere un numero intero senza centesimi.');

            return null;
        }
    }
}
