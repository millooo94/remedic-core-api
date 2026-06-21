<?php

namespace App\Console\Commands;

use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use Illuminate\Console\Command;

class ClearMiodottoreAvailabilityDataCommand extends Command
{
    protected $signature = 'remedic:clear-miodottore-availability-data
        {--dry-run : Mostra il riepilogo senza eliminare nulla}';

    protected $description = 'Rimuove solo disponibilita ed eccezioni importate da MioDottore, senza toccare dati manuali o appuntamenti.';

    public function handle(): int
    {
        $rulesQuery = ProfessionalAvailabilityRule::query()->where('source', 'miodottore');
        $exceptionsQuery = ProfessionalAvailabilityException::query()->where('source', 'miodottore');

        $rulesCount = (clone $rulesQuery)->count();
        $exceptionsCount = (clone $exceptionsQuery)->count();

        $this->info('Riepilogo dati MioDottore disponibili per la pulizia:');
        $this->table(
            ['Categoria', 'Righe'],
            [
                ['Disponibilita ricorrenti importate', $rulesCount],
                ['Eccezioni importate', $exceptionsCount],
                ['Totale', $rulesCount + $exceptionsCount],
            ],
        );

        if ($this->option('dry-run')) {
            $this->comment('Dry run completato: nessun dato e stato eliminato.');

            return self::SUCCESS;
        }

        $deletedRules = $rulesQuery->delete();
        $deletedExceptions = $exceptionsQuery->delete();

        $this->info(sprintf(
            'Pulizia completata: eliminate %d disponibilita ricorrenti e %d eccezioni importate da MioDottore.',
            $deletedRules,
            $deletedExceptions,
        ));

        return self::SUCCESS;
    }
}
