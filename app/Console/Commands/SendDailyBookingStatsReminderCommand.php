<?php

namespace App\Console\Commands;

use App\Services\DailyBookingStatsService;
use Illuminate\Console\Command;

class SendDailyBookingStatsReminderCommand extends Command
{
    protected $signature = 'daily-booking-stats:remind';

    protected $description = 'Invia il promemoria giornaliero per prenotazioni e disdette ricevute';

    public function handle(DailyBookingStatsService $service): int
    {
        $count = $service->sendReminder();
        $this->info(sprintf('Promemoria statistiche prenotazioni inviati: %d.', $count));

        return self::SUCCESS;
    }
}
