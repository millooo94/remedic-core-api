<?php

namespace App\Console\Commands;

use App\Mail\WeeklyCenterSummaryMail;
use App\Services\WeeklyCenterSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendWeeklyCenterSummaryCommand extends Command
{
    protected $signature = 'reminders:send-weekly-center-summary';

    protected $description = 'Invia il riepilogo settimanale del centro ogni domenica alle 10:30.';

    public function handle(WeeklyCenterSummaryService $summaryService): int
    {
        $summary = $summaryService->buildSummary();
        $recipient = 'humancaretelemedicine@gmail.com';

        Mail::to($recipient)->send(new WeeklyCenterSummaryMail($summary));

        $this->info(sprintf('Riepilogo settimanale inviato a %s per il periodo %s.', $recipient, $summary['period']['label']));

        return self::SUCCESS;
    }
}

