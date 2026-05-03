<?php

namespace App\Observers;

use App\Models\ExpenseRecord;
use App\Services\ExpenseCompetenceAllocationService;
use App\Services\PerformancePaymentStatusSyncService;

class ExpenseRecordObserver
{
    public function __construct(
        private readonly ExpenseCompetenceAllocationService $allocationService,
        private readonly PerformancePaymentStatusSyncService $paymentStatusSyncService,
    ) {
    }

    public function created(ExpenseRecord $expenseRecord): void
    {
        $this->allocationService->syncForExpenseRecord($expenseRecord);
    }

    public function updated(ExpenseRecord $expenseRecord): void
    {
        if ($expenseRecord->source_performance_record_id !== null && $expenseRecord->wasChanged('payment_status')) {
            $this->paymentStatusSyncService->syncPerformanceAndSiblingExpensesFromExpense($expenseRecord);
        }

        if (! $this->shouldResync($expenseRecord)) {
            return;
        }

        $this->allocationService->syncForExpenseRecord($expenseRecord);
    }

    private function shouldResync(ExpenseRecord $expenseRecord): bool
    {
        return $expenseRecord->wasChanged([
            'amount',
            'expense_date',
            'competence_month',
            'competence_year',
            'competence_start_date',
            'competence_end_date',
        ]);
    }
}
