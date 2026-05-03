<?php

use App\Services\PerformanceExpenseBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('performance_records') || ! Schema::hasTable('expense_records')) {
            return;
        }

        app(PerformanceExpenseBackfillService::class)->syncLinkedExpenses(true);
    }

    public function down(): void
    {
        // Backfill irreversibile: i costi rigenerati restano coerenti con la logica corrente.
    }
};
