<?php

namespace App\Console\Commands;

use App\Services\PerformanceExpenseBackfillService;
use Illuminate\Console\Command;

class ResyncPerformanceExpensesCommand extends Command
{
    protected $signature = 'performance-records:resync-expenses {--all : Ricalcola anche le prestazioni senza costo diretto}';

    protected $description = 'Rigenera i costi variabili collegati alle prestazioni effettuate con la logica economica corrente';

    public function handle(PerformanceExpenseBackfillService $service): int
    {
        $processed = $service->syncLinkedExpenses(! $this->option('all'));

        $this->info(sprintf(
            'Prestazioni riallineate: %d%s.',
            $processed,
            $this->option('all') ? ' (tutte le prestazioni)' : ' (solo con costo diretto)',
        ));

        return self::SUCCESS;
    }
}
