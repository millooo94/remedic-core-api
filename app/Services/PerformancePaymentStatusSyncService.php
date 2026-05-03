<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\ExpenseRecord;
use App\Models\PerformanceRecord;

class PerformancePaymentStatusSyncService
{
    public function normalize(PaymentStatus|string|null $value): string
    {
        $raw = $value instanceof PaymentStatus ? $value->value : $value;

        return PaymentStatus::tryFrom((string) $raw)?->value ?? PaymentStatus::DaPagare->value;
    }

    public function syncPerformanceAndSiblingExpensesFromExpense(ExpenseRecord $expenseRecord): void
    {
        if (! $expenseRecord->source_performance_record_id) {
            return;
        }

        $paymentStatus = $this->normalize($expenseRecord->payment_status);

        PerformanceRecord::query()
            ->whereKey($expenseRecord->source_performance_record_id)
            ->update([
                'payment_status' => $paymentStatus,
                'updated_at' => now(),
            ]);

        ExpenseRecord::query()
            ->where('source_performance_record_id', $expenseRecord->source_performance_record_id)
            ->whereKeyNot($expenseRecord->id)
            ->update([
                'payment_status' => $paymentStatus,
                'updated_at' => now(),
            ]);
    }
}
