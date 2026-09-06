<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reminders:send-counting')->dailyAt('08:00');
Schedule::command('daily-booking-stats:remind')->dailyAt('21:30')->timezone('Europe/Rome');
Schedule::command('reminders:send-weekly-center-summary')
    ->sundays()
    ->at('10:30')
    ->timezone('Europe/Rome');
Schedule::command('marketing:send-scheduled-campaigns')->everyMinute();
Schedule::command('newsletter:send-scheduled-campaigns')->everyMinute();
Schedule::command('page-reviews:sync-google')->dailyAt('05:30')->timezone('Europe/Rome')->withoutOverlapping();
