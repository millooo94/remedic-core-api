<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\ExpenseRecord;
use App\Models\User;
use App\Support\Filters\ExpenseRecordFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly ExpenseRecordFilters $filters,
        private readonly PerformancePaymentStatusSyncService $paymentStatusSyncService,
    ) {}

    public function baseQuery(array $filters = []): Builder
    {
        $query = $this->filteredQuery($filters)->with(['category', 'template', 'competenceAllocations']);

        return $this->filters->applySort($query, $filters['sort'] ?? null);
    }

    public function summary(array $filters = []): array
    {
        $query = $this->filteredQuery($filters);

        $fixedCosts = round((float) (clone $query)
            ->where('type', 'fixed')
            ->sum('amount'), 2);
        $variableCosts = round((float) (clone $query)
            ->where('type', 'variable')
            ->sum('amount'), 2);
        $ordinaryCosts = round((float) (clone $query)
            ->where('nature', 'ordinary')
            ->sum('amount'), 2);
        $specialCosts = round((float) (clone $query)
            ->where('nature', 'special')
            ->sum('amount'), 2);
        $unpaidCosts = round((float) (clone $query)
            ->where('payment_status', PaymentStatus::DaPagare->value)
            ->sum('amount'), 2);

        return [
            'filters' => [
                'q' => $filters['q'] ?? null,
                'type' => $filters['type'] ?? null,
                'nature' => $filters['nature'] ?? null,
                'expense_category_id' => isset($filters['expense_category_id']) ? (int) $filters['expense_category_id'] : null,
                'payment_status' => $filters['payment_status'] ?? null,
                'month' => isset($filters['month']) ? (int) $filters['month'] : null,
                'year' => isset($filters['year']) ? (int) $filters['year'] : null,
                'competence_date_from' => $filters['competence_date_from'] ?? null,
                'competence_date_to' => $filters['competence_date_to'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ],
            'totals' => [
                'records_count' => (clone $query)->count(),
                'total_costs' => round($fixedCosts + $variableCosts, 2),
                'fixed_costs' => $fixedCosts,
                'variable_costs' => $variableCosts,
                'ordinary_costs' => $ordinaryCosts,
                'special_costs' => $specialCosts,
                'unpaid_costs' => $unpaidCosts,
            ],
        ];
    }

    public function create(array $payload, User $actor): ExpenseRecord
    {
        return ExpenseRecord::query()->create($this->buildAttributes($payload));
    }

    public function update(ExpenseRecord $expenseRecord, array $payload, User $actor): ExpenseRecord
    {
        if ($expenseRecord->source_performance_record_id !== null) {
            $expenseRecord->fill([
                'payment_status' => $this->paymentStatusSyncService->normalize($payload['payment_status'] ?? null),
            ]);
            $expenseRecord->save();

            return $expenseRecord->fresh(['category', 'template', 'competenceAllocations']);
        }

        $expenseRecord->fill($this->buildAttributes($payload));
        $expenseRecord->save();

        return $expenseRecord->fresh(['category', 'template', 'competenceAllocations']);
    }

    public function delete(ExpenseRecord $expenseRecord, User $actor): void
    {
        if ($expenseRecord->source_performance_record_id !== null) {
            throw ValidationException::withMessages([
                'expense_record' => 'Questo costo e collegato a una prestazione effettuata. Puoi modificarne solo lo stato pagamento oppure intervenire direttamente dalla prestazione collegata.',
            ]);
        }

        DB::transaction(function () use ($expenseRecord): void {
            $expenseRecord->delete();
        });
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
            'nature' => $payload['nature'] ?? 'ordinary',
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

    private function filteredQuery(array $filters = []): Builder
    {
        $query = ExpenseRecord::query();

        $this->filters->apply($query, $filters);

        return $query;
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
