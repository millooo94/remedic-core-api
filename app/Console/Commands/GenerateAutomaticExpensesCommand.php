<?php

namespace App\Console\Commands;

use App\Services\AutomaticExpenseGenerationService;
use Illuminate\Console\Command;

class GenerateAutomaticExpensesCommand extends Command
{
    protected $signature = 'costs:generate-automatic';

    protected $description = 'Genera i costi automatici in base alle regole attive';

    public function handle(AutomaticExpenseGenerationService $service): int
    {
        $result = $service->generateDue();

        $this->info(sprintf(
            'Controllate %d regole, generati %d costi, saltati %d duplicati.',
            $result['templates_checked'],
            $result['generated'],
            $result['skipped_duplicates'],
        ));

        return self::SUCCESS;
    }
}

