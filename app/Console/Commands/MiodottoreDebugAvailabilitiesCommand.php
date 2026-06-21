<?php

namespace App\Console\Commands;

use App\Services\MiodottoreAccessService;
use Illuminate\Console\Command;

class MiodottoreDebugAvailabilitiesCommand extends Command
{
    protected $signature = 'miodottore:debug-availabilities
        {--days=30 : Numero di giorni da oggi da analizzare}
        {--from= : Data iniziale YYYY-MM-DD}
        {--to= : Data finale YYYY-MM-DD}
        {--doctor= : Filtra o evidenzia un medico/professionista specifico}';

    protected $description = 'Legge in sola lettura le disponibilita da MioDottore e salva artifact JSON di debug';

    public function handle(MiodottoreAccessService $accessService): int
    {
        $filters = [
            'days' => (int) ($this->option('days') ?? 30),
            'from' => $this->normalizeStringOption($this->option('from')),
            'to' => $this->normalizeStringOption($this->option('to')),
            'doctor' => $this->normalizeStringOption($this->option('doctor')),
        ];

        try {
            $result = $accessService->debugMiodottoreAvailabilities($filters);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Esito accesso: '.(($result['access_check']['success'] ?? false) ? 'verificato' : 'non verificato'));

        if (($result['access_check']['output_dir'] ?? '') !== '') {
            $this->line("Artefatti verifica: storage/app/{$result['access_check']['output_dir']}");
        }

        if (! $result['success']) {
            $this->error($result['message']);
            if ($result['output_dir'] !== '') {
                $this->line("Cartella artifact generata: storage/app/{$result['output_dir']}");
            }

            return self::FAILURE;
        }

        $summary = $result['summary'];

        $this->info($result['message']);
        $this->line("Cartella artifact generata: storage/app/{$result['output_dir']}");
        $this->line('Numero agende/medici: '.$summary['schedules_count']);
        $this->line('Numero professionisti normalizzati: '.$summary['professionals_count']);
        $this->line('Numero workperiods dichiarati: '.$summary['workperiods_count']);
        $this->line('Numero appuntamenti letti: '.$summary['appointments_count']);
        $this->line('Numero blocchi letti: '.$summary['blocks_count']);
        $this->line('Numero giorni normalizzati: '.$summary['normalized_days_count']);
        $this->line('orari_settimanali: '.$summary['weekly_hours_count']);
        $this->line('eccezioni_disponibilita: '.$summary['daily_available_exceptions_count']);
        $this->line('appuntamenti: '.$summary['appointments_count']);
        $this->line('blocchi_non_disponibilita_ignorati: '.$summary['ignored_unavailable_blocks_count']);
        $this->line('Warning: '.$summary['warnings_count']);

        $warnings = $result['result']['warnings'] ?? $result['result']['result']['warnings'] ?? null;
        if (is_array($warnings) && count($warnings) > 0) {
            $this->newLine();
            $this->comment('Warning principali:');
            foreach (array_slice($warnings, 0, 8) as $warning) {
                if (is_scalar($warning)) {
                    $this->line('- '.(string) $warning);
                    continue;
                }

                if (is_array($warning)) {
                    $this->line('- '.json_encode($warning, JSON_UNESCAPED_SLASHES));
                }
            }
        }

        return self::SUCCESS;
    }

    private function normalizeStringOption(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
