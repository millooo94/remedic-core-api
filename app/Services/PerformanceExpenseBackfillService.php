<?php

namespace App\Services;

use App\Models\PerformanceRecord;

class PerformanceExpenseBackfillService
{
    public function __construct(
        private readonly PerformanceExpenseSyncService $performanceExpenseSyncService,
    ) {
    }

    public function syncLinkedExpenses(bool $onlyWithDirectCosts = true): int
    {
        $processed = 0;

        $query = PerformanceRecord::query()
            ->with('splits.professional')
            ->orderBy('id');

        if ($onlyWithDirectCosts) {
            $query->where('direct_cost', '>', 0);
        }

        $query->chunkById(100, function ($records) use (&$processed): void {
            foreach ($records as $record) {
                $this->performanceExpenseSyncService->syncFromPerformanceRecord($record);
                $processed++;
            }
        });

        return $processed;
    }
}
