<?php

namespace App\Services;

use App\Models\ExpenseRecord;
use App\Models\User;
use App\Support\Filters\ExpenseRecordFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ExpenseService
{
    public function __construct(
        private readonly ExpenseRecordFilters $filters,
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        $query = ExpenseRecord::query()->with(['category', 'template']);

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

        return $expenseRecord->fresh(['category', 'template']);
    }

    private function buildAttributes(array $payload): array
    {
        $expenseDate = Carbon::parse($payload['expense_date']);

        return [
            'expense_category_id' => $payload['expense_category_id'],
            'expense_template_id' => $payload['expense_template_id'] ?? null,
            'source' => 'manual',
            'generation_key' => null,
            'expense_date' => $expenseDate->toDateString(),
            'competence_month' => (int) ($payload['competence_month'] ?? $expenseDate->format('n')),
            'competence_year' => (int) ($payload['competence_year'] ?? $expenseDate->format('Y')),
            'description' => $payload['description'],
            'type' => $payload['type'],
            'amount' => number_format((float) str_replace(',', '.', (string) $payload['amount']), 2, '.', ''),
            'supplier' => $payload['supplier'] ?? null,
            'payment_status' => $payload['payment_status'] ?? 'pagata',
            'notes' => $payload['notes'] ?? null,
        ];
    }
}
