<?php

namespace App\Console\Commands;

use App\Services\MiodottoreAvailabilitySyncService;
use Illuminate\Console\Command;

class MiodottoreSyncAvailabilitiesCommand extends Command
{
    protected $signature = 'miodottore:sync-availabilities
        {--days=30 : Numero di giorni da oggi da sincronizzare}
        {--from= : Data iniziale YYYY-MM-DD}
        {--to= : Data finale YYYY-MM-DD}
        {--doctor= : Filtra un medico MioDottore per nome schedule/display}
        {--write : Esegue davvero la scrittura nel database}';

    protected $description = 'Sincronizza in modo controllato le disponibilita dichiarate da MioDottore verso regole settimanali ed eccezioni positive interne';

    public function handle(MiodottoreAvailabilitySyncService $syncService): int
    {
        $filters = [
            'days' => (int) ($this->option('days') ?? 30),
            'from' => $this->normalizeStringOption($this->option('from')),
            'to' => $this->normalizeStringOption($this->option('to')),
            'doctor' => $this->normalizeStringOption($this->option('doctor')),
        ];
        $write = (bool) $this->option('write');

        try {
            $result = $syncService->syncNormalizedAvailabilities($filters, $write);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Accesso MioDottore: '.(($result['access_check']['success'] ?? false) ? 'OK' : 'NON VERIFICATO'));

        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        if (($plan['from'] ?? null) && ($plan['to'] ?? null)) {
            $this->line("Range: {$plan['from']} -> {$plan['to']}");
        }

        $this->line('Modalita: '.($write ? 'WRITE' : 'DRY-RUN'));
        $this->line("Cartella artifact: storage/app/{$result['output_dir']}");

        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->line('Professionisti mappati: '.(int) ($plan['mapped_professionals'] ?? 0));
        $this->line('Professionisti non mappati: '.count($plan['unmapped_professionals'] ?? []));
        $this->line('Orari settimanali da importare: '.(int) ($plan['weekly_rule_rows'] ?? 0));
        $this->line('Eccezioni positive da importare: '.(int) ($plan['daily_available_exception_rows'] ?? 0));
        $this->line('Blocchi/non disponibilita ignorati: '.(int) ($plan['ignored_unavailable_blocks'] ?? 0));
        $this->line('Regole MioDottore esistenti da sostituire: '.(int) ($plan['delete_existing_miodottore_rules'] ?? 0));
        $this->line('Eccezioni MioDottore esistenti nel range: '.(int) ($plan['delete_existing_miodottore_exceptions_in_range'] ?? 0));

        $unmappedProfessionals = $plan['unmapped_professionals'] ?? [];
        if (is_array($unmappedProfessionals) && $unmappedProfessionals !== []) {
            $this->newLine();
            $this->comment('Professionisti non mappati:');
            foreach (array_slice($unmappedProfessionals, 0, 10) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $label = (string) ($item['provider_name'] ?? 'Professionista sconosciuto');
                $scheduleId = $item['provider_schedule_id'] ?? 'n/d';
                $reason = (string) ($item['reason'] ?? 'mapping non trovato');
                $this->line("- {$label} [schedule {$scheduleId}] - {$reason}");
            }
        }

        if (! $write) {
            $this->newLine();
            $this->comment('Nessuna scrittura eseguita. Usa --write per confermare.');

            return self::SUCCESS;
        }

        $dbResult = is_array($result['db_result'] ?? null) ? $result['db_result'] : [];
        $this->line('Regole MioDottore cancellate: '.(int) ($dbResult['deleted_rule_rows'] ?? 0));
        $this->line('Eccezioni MioDottore cancellate nel range: '.(int) ($dbResult['deleted_exception_rows'] ?? 0));
        $this->line('Regole inserite: '.(int) ($dbResult['inserted_rule_rows'] ?? 0));
        $this->line('Eccezioni positive inserite: '.(int) ($dbResult['inserted_exception_rows'] ?? 0));
        $this->line('Righe inserite totali: '.(int) ($dbResult['inserted_rows'] ?? 0));
        $this->info('Sync completata.');

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
