<?php

namespace Tests\Feature;

use App\Mail\WeeklyCenterSummaryMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendWeeklyCenterSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_the_weekly_center_summary_email_to_humancare(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-04-26 10:30:00', 'Europe/Rome'));

        $this->artisan('reminders:send-weekly-center-summary')
            ->assertSuccessful();

        Mail::assertSent(WeeklyCenterSummaryMail::class, function (WeeklyCenterSummaryMail $mail): bool {
            return $mail->hasTo('humancaretelemedicine@gmail.com');
        });

        Carbon::setTestNow();
    }
}

