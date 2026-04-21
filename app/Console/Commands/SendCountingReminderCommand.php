<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

class SendCountingReminderCommand extends Command
{
    protected $signature = 'reminders:send-counting';

    protected $description = 'Invia il promemoria interno per il conteggio del periodo ai destinatari configurati.';

    public function handle(ReminderService $reminderService): int
    {
        $sent = $reminderService->sendDueReminders();

        $this->info($sent ? 'Promemoria inviato.' : 'Nessun promemoria dovuto oggi.');

        return self::SUCCESS;
    }
}
