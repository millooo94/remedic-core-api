<?php

namespace App\Services;

use App\Mail\CountingReminderMail;
use App\Models\Reminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class ReminderService
{
    public function sendDueReminders(?Carbon $date = null): bool
    {
        $date ??= now();
        $today = $date->copy()->startOfDay();
        $sentAtLeastOne = false;

        $reminders = Reminder::query()
            ->where('is_active', true)
            ->get();

        foreach ($reminders as $reminder) {
            if (!$this->isDueToday($reminder, $today)) {
                continue;
            }

            if ($reminder->last_sent_at && $reminder->last_sent_at->isSameDay($today)) {
                continue;
            }

            Mail::to($reminder->recipient_email)->send(
                new CountingReminderMail(
                    reminderDate: $today,
                    companyName: 'Humancare Telemedicine S.r.l.',
                    subjectLine: $reminder->subject,
                    bodyText: $reminder->body,
                ),
            );

            $reminder->last_sent_at = $today;
            $reminder->save();
            $sentAtLeastOne = true;
        }

        return $sentAtLeastOne;
    }

    private function isDueToday(Reminder $reminder, Carbon $today): bool
    {
        return match ($reminder->frequency) {
            'weekly' => (int) ($reminder->day_of_week ?? 1) === $today->dayOfWeekIso,
            'monthly' => $this->dayMatchesMonth($today, (int) ($reminder->day_of_month ?? 20)),
            'quarterly' => $today->month % 3 === 0 && $this->dayMatchesMonth($today, (int) ($reminder->day_of_month ?? 20)),
            'yearly' => $today->month === 1 && $this->dayMatchesMonth($today, (int) ($reminder->day_of_month ?? 20)),
            default => false,
        };
    }

    private function dayMatchesMonth(Carbon $today, int $day): bool
    {
        $normalized = max(1, min($day, $today->daysInMonth));
        return $today->day === $normalized;
    }
}
