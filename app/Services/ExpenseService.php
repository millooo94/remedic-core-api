<?php

namespace App\Services;

use App\Models\ExpenseRecord;
use App\Models\User;
use App\Support\Filters\ExpenseRecordFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly ExpenseRecordFilters $filters,
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        $query = ExpenseRecord::query()->with(['category', 'template', 'competenceAllocations']);

        $this->filters->apply($query, $filters);

        return $this->filters->applySort($query, $filters['sort'] ?? null);
    }

    public function create(array $payload, User $actor): ExpenseRecord
    {
        return ExpenseRecord::query()->create($this->buildAttributes($payload));
    }

    public function update(ExpenseRecord $expenseRecord, array $payload, User $actor): ExpenseRecord
    {
        $expenseRecord->fill($this->buildAttributes($payload));
        $expenseRecord->save();

        return $expenseRecord->fresh(['category', 'template', 'competenceAllocations']);
    }

    private function buildAttributes(array $payload): array
    {
        $expenseDate = Carbon::parse($payload['expense_date']);
        $competenceStart = $this->resolveCompetenceStart($payload, $expenseDate);
        $competenceEnd = $this->resolveCompetenceEnd($payload, $competenceStart);
        $monthsCount = max(1, $competenceStart->diffInMonths($competenceEnd) + 1);

        return [
            'expense_category_id' => $payload['expense_category_id'],
            'expense_template_id' => $payload['expense_template_id'] ?? null,
            'source' => 'manual',
            'generation_key' => null,
            'expense_date' => $expenseDate->toDateString(),
            'competence_start_date' => $competenceStart->toDateString(),
            'competence_end_date' => $competenceEnd->toDateString(),
            'competence_months_count' => $monthsCount,
            'competence_month' => (int) $competenceStart->format('n'),
            'competence_year' => (int) $competenceStart->format('Y'),
            'description' => $payload['description'],
            'type' => $payload['type'],
            'amount' => $this->normalizeMoneyAmount($payload['amount'] ?? 0),
            'supplier' => $payload['supplier'] ?? null,
            'payment_status' => $payload['payment_status'] ?? 'da_pagare',
            'notes' => $payload['notes'] ?? null,
        ];
    }

    private function normalizeMoneyAmount(mixed $raw): string
    {
        $normalized = is_string($raw)
            ? str_replace(',', '.', trim($raw))
            : $raw;

        $parsed = (float) $normalized;

        return number_format(max(0.01, $parsed), 2, '.', '');
    }

    private function resolveCompetenceStart(array $payload, Carbon $expenseDate): Carbon
    {
        if (! empty($payload['competence_start_date'])) {
            return Carbon::parse($payload['competence_start_date'])->startOfMonth();
        }

        if (! empty($payload['competence_year']) && ! empty($payload['competence_month'])) {
            return Carbon::create((int) $payload['competence_year'], (int) $payload['competence_month'], 1)->startOfMonth();
        }

        return $expenseDate->copy()->startOfMonth();
    }

    private function resolveCompetenceEnd(array $payload, Carbon $competenceStart): Carbon
    {
        if (! empty($payload['competence_end_date'])) {
            $end = Carbon::parse($payload['competence_end_date'])->startOfMonth();

            if ($end->lt($competenceStart)) {
                throw ValidationException::withMessages([
                    'competence_end_date' => 'La competenza finale deve essere uguale o successiva alla competenza iniziale.',
                ]);
            }

            return $end;
        }

        return $competenceStart->copy();
    }
}
