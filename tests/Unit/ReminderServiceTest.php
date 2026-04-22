<?php

namespace Tests\Unit;

use App\Mail\CountingReminderMail;
use App\Models\Reminder;
use App\Services\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_the_monthly_reminder_on_day_twenty(): void
    {
        Mail::fake();

        Reminder::query()->create([
            'title' => 'Promemoria mensile',
            'recipient_email' => 'humancaretelemedicine@gmail.com',
            'subject' => 'Promemoria conteggio professionisti Remedic',
            'body' => 'Verifica i conteggi mensili.',
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'is_active' => true,
        ]);

        $sent = app(ReminderService::class)->sendDueReminders(now()->setDate(2026, 5, 20));

        $this->assertTrue($sent);
        Mail::assertSent(CountingReminderMail::class, function (CountingReminderMail $mail): bool {
            return $mail->hasTo('humancaretelemedicine@gmail.com')
                && ! $mail->hasCc('humancaretelemedicine@gmail.com');
        });
    }

    #[Test]
    public function it_does_not_send_the_monthly_reminder_on_other_days(): void
    {
        Mail::fake();

        Reminder::query()->create([
            'title' => 'Promemoria mensile',
            'recipient_email' => 'humancaretelemedicine@gmail.com',
            'subject' => 'Promemoria conteggio professionisti Remedic',
            'body' => 'Verifica i conteggi mensili.',
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'is_active' => true,
        ]);

        $sent = app(ReminderService::class)->sendDueReminders(now()->setDate(2026, 5, 19));

        $this->assertFalse($sent);
        Mail::assertNothingSent();
    }

    #[Test]
    public function it_always_sends_a_copy_of_the_reminder_to_humancare_without_duplicate_cc_entries(): void
    {
        Mail::fake();

        Reminder::query()->create([
            'title' => 'Promemoria mensile',
            'recipient_email' => 'coordinamento@remedic.it',
            'subject' => 'Promemoria conteggio professionisti Remedic',
            'body' => 'Verifica i conteggi mensili.',
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'is_active' => true,
        ]);

        $sent = app(ReminderService::class)->sendDueReminders(now()->setDate(2026, 5, 20));

        $this->assertTrue($sent);
        Mail::assertSent(CountingReminderMail::class, function (CountingReminderMail $mail): bool {
            return $mail->hasTo('coordinamento@remedic.it')
                && $mail->hasCc('humancaretelemedicine@gmail.com');
        });
    }
}
