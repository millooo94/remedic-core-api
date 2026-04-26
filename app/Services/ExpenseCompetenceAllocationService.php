<?php

namespace App\Services;

use App\Models\ExpenseRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseCompetenceAllocationService
{
    public function syncForExpenseRecord(ExpenseRecord $expenseRecord): void
    {
        $start = $this->resolveStartMonth($expenseRecord);
        $end = $this->resolveEndMonth($expenseRecord, $start);
        $months = max(1, $start->diffInMonths($end) + 1);

        $expenseRecord->forceFill([
            'competence_start_date' => $start->toDateString(),
            'competence_end_date' => $end->toDateString(),
            'competence_month' => (int) $start->format('n'),
            'competence_year' => (int) $start->format('Y'),
            'competence_months_count' => $months,
        ]);

        if ($expenseRecord->isDirty(['competence_start_date', 'competence_end_date', 'competence_month', 'competence_year', 'competence_months_count'])) {
            $expenseRecord->saveQuietly();
        }

        $allocations = $this->buildAllocations($expenseRecord, $start, $months);

        DB::transaction(function () use ($expenseRecord, $allocations): void {
            $expenseRecord->competenceAllocations()->delete();
            $expenseRecord->competenceAllocations()->createMany($allocations);
        });
    }

    private function resolveStartMonth(ExpenseRecord $expenseRecord): Carbon
    {
        if ($expenseRecord->competence_start_date) {
            return Carbon::parse($expenseRecord->competence_start_date)->startOfMonth();
        }

        if ($expenseRecord->competence_year && $expenseRecord->competence_month) {
            return Carbon::create((int) $expenseRecord->competence_year, (int) $expenseRecord->competence_month, 1)->startOfMonth();
        }

        return Carbon::parse($expenseRecord->expense_date ?? now())->startOfMonth();
    }

    private function resolveEndMonth(ExpenseRecord $expenseRecord, Carbon $start): Carbon
    {
        if ($expenseRecord->competence_end_date) {
            $end = Carbon::parse($expenseRecord->competence_end_date)->startOfMonth();

            return $end->lt($start) ? $start->copy() : $end;
        }

        return $start->copy();
    }

    private function buildAllocations(ExpenseRecord $expenseRecord, Carbon $start, int $months): array
    {
        $totalCents = (int) round(((float) $expenseRecord->amount) * 100);
        $baseCents = (int) floor($totalCents / $months);
        $remainder = $totalCents - ($baseCents * $months);

        $allocations = [];
        for ($index = 0; $index < $months; $index++) {
            $monthDate = $start->copy()->addMonths($index)->startOfMonth();
            $cents = $baseCents + ($index < $remainder ? 1 : 0);

            $allocations[] = [
                'competence_date' => $monthDate->toDateString(),
                'competence_month' => (int) $monthDate->format('n'),
                'competence_year' => (int) $monthDate->format('Y'),
                'allocated_amount' => number_format($cents / 100, 2, '.', ''),
            ];
        }

        return $allocations;
    }
}
