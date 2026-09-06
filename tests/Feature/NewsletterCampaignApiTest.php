<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\NewsletterCampaignDeliveryStatus;
use App\Enums\NewsletterCampaignStatus;
use App\Enums\NewsletterSubscriberStatus;
use App\Enums\UserRole;
use App\Jobs\SendNewsletterCampaignDeliveryJob;
use App\Mail\NewsletterCampaignMail;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Services\NewsletterCampaignService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NewsletterCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        Mail::fake();
        Queue::fake();
    }

    #[Test]
    public function it_creates_and_updates_a_newsletter_campaign_draft_with_its_own_permission(): void
    {
        $this->getJson('/api/v1/admin/newsletter-campaigns')->assertUnauthorized();
        $this->actingAsAdmin();

        $created = $this->postJson('/api/v1/admin/newsletter-campaigns', $this->payload())
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('recipient_count', 0);
        $campaignId = $created->json('id');

        $this->putJson("/api/v1/admin/newsletter-campaigns/{$campaignId}", $this->payload([
            'subject' => 'Oggetto aggiornato',
            'preheader' => 'Anteprima aggiornata',
        ]))->assertOk()->assertJsonPath('subject', 'Oggetto aggiornato');
    }

    #[Test]
    public function only_confirmed_subscribers_are_snapshotted_and_a_second_launch_is_blocked(): void
    {
        $this->actingAsAdmin();
        $first = $this->subscriber('one@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $second = $this->subscriber('two@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $this->subscriber('pending@example.test', NewsletterSubscriberStatus::PENDING);
        $this->subscriber('unsubscribed@example.test', NewsletterSubscriberStatus::UNSUBSCRIBED);
        $campaign = $this->campaign();

        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")
            ->assertOk()
            ->assertJsonPath('status', 'sending')
            ->assertJsonPath('recipient_count', 2);

        $this->assertDatabaseCount('newsletter_campaign_deliveries', 2);
        $this->assertDatabaseHas('newsletter_campaign_deliveries', ['newsletter_subscriber_id' => $first->id, 'email_snapshot' => 'one@example.test']);
        $this->assertDatabaseHas('newsletter_campaign_deliveries', ['newsletter_subscriber_id' => $second->id, 'email_snapshot' => 'two@example.test']);
        $delivery = $campaign->deliveries()->with('subscriber')->firstOrFail();
        $this->assertSame($campaign->id, $delivery->campaign->id);
        $this->assertSame($delivery->email_snapshot, $delivery->subscriber->email);
        Queue::assertPushed(SendNewsletterCampaignDeliveryJob::class, 2);
        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")->assertUnprocessable();
    }

    #[Test]
    public function it_rejects_past_schedules_and_locks_a_campaign_after_it_has_been_sent(): void
    {
        $this->actingAsAdmin();
        $this->subscriber('immutable@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $campaign = $this->campaign();

        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/schedule", ['scheduled_at' => now()->subMinute()->toIso8601String()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")->assertOk();
        app(NewsletterCampaignService::class)->processDelivery($campaign->deliveries()->firstOrFail()->id);

        $this->assertSame(NewsletterCampaignStatus::SENT, $campaign->fresh()->status);
        $this->putJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}", $this->payload(['subject' => 'Non modificabile']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('campaign');
    }

    #[Test]
    public function it_suppresses_a_recipient_who_unsubscribes_after_snapshot_and_keeps_history(): void
    {
        $this->actingAsAdmin();
        $subscriber = $this->subscriber('unsubscribe@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $campaign = $this->campaign();
        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")->assertOk();
        $delivery = $campaign->deliveries()->firstOrFail();
        $subscriber->update(['status' => NewsletterSubscriberStatus::UNSUBSCRIBED, 'unsubscribed_at' => now()]);

        app(NewsletterCampaignService::class)->processDelivery($delivery->id);

        Mail::assertNothingSent();
        $this->assertSame(NewsletterCampaignDeliveryStatus::SUPPRESSED, $delivery->fresh()->delivery_status);
        $this->assertSame(1, $campaign->fresh()->suppressed_count);
        $this->assertDatabaseHas('newsletter_campaign_deliveries', ['id' => $delivery->id, 'newsletter_subscriber_id' => $subscriber->id]);
    }

    #[Test]
    public function every_real_newsletter_email_contains_a_valid_signed_unsubscribe_link_for_its_subscriber(): void
    {
        $this->actingAsAdmin();
        $subscriber = $this->subscriber('link@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $campaign = $this->campaign();
        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")->assertOk();
        $delivery = $campaign->deliveries()->firstOrFail();

        app(NewsletterCampaignService::class)->processDelivery($delivery->id);

        Mail::assertSent(NewsletterCampaignMail::class, function (NewsletterCampaignMail $mail) use ($subscriber): bool {
            return $mail->unsubscribeUrl !== null
                && str_contains($mail->unsubscribeUrl, '/newsletter/unsubscribe/'.$subscriber->public_id)
                && str_contains($mail->unsubscribeUrl, 'signature=');
        });
        $mail = Mail::sent(NewsletterCampaignMail::class)->first();
        $mailable = $mail instanceof NewsletterCampaignMail ? $mail : $mail->getMailable();
        $this->assertNotNull($mailable->unsubscribeUrl);
        $this->assertStringContainsString(str_replace('&', '&amp;', $mailable->unsubscribeUrl), $mailable->render());
        config()->set('newsletter.website_url', 'https://website.example.test');
        $this->get($mailable->unsubscribeUrl)->assertRedirect('https://website.example.test/newsletter/disiscrizione?status=unsubscribed');
    }

    #[Test]
    public function test_email_uses_campaign_content_without_creating_snapshot_or_changing_counts(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->campaign();

        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-test", ['email' => 'test@example.test'])
            ->assertOk()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('recipient_count', 0);

        $this->assertDatabaseCount('newsletter_campaign_deliveries', 0);
        Mail::assertSent(NewsletterCampaignMail::class, fn (NewsletterCampaignMail $mail): bool => $mail->isTest && $mail->subjectLine === 'Newsletter di prova' && $mail->preheader === 'Anteprima della newsletter');
    }

    #[Test]
    public function scheduled_campaigns_start_once_when_due_and_are_editable_only_before_sending(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');
        try {
            $this->actingAsAdmin();
            $this->subscriber('scheduled@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
            $campaign = $this->campaign();
            $future = now()->addHour()->toIso8601String();

            $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/schedule", ['scheduled_at' => $future])
                ->assertOk()->assertJsonPath('status', 'scheduled');
            $this->putJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}", $this->payload(['subject' => 'Programmata aggiornata', 'scheduled_at' => $future]))
                ->assertOk();
            $this->assertSame(0, app(NewsletterCampaignService::class)->dispatchScheduledCampaigns());

            Carbon::setTestNow(now()->addHours(2));
            $this->assertSame(1, app(NewsletterCampaignService::class)->dispatchScheduledCampaigns());
            $this->assertSame(0, app(NewsletterCampaignService::class)->dispatchScheduledCampaigns());
            $this->assertSame(NewsletterCampaignStatus::SENDING, $campaign->fresh()->status);
            $this->putJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}", $this->payload())->assertUnprocessable();
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function a_delivery_failure_keeps_sent_history_and_updates_campaign_counts_without_duplicates(): void
    {
        $this->actingAsAdmin();
        $this->subscriber('sent@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $this->subscriber('failed@example.test', NewsletterSubscriberStatus::SUBSCRIBED);
        $campaign = $this->campaign();
        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")->assertOk();
        $deliveries = $campaign->deliveries()->orderBy('id')->get();

        app(NewsletterCampaignService::class)->processDelivery($deliveries[0]->id);
        app(NewsletterCampaignService::class)->markDeliveryFailed($deliveries[1]->id);
        app(NewsletterCampaignService::class)->processDelivery($deliveries[0]->id);

        $campaign->refresh();
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, $campaign->failed_count);
        $this->assertSame(NewsletterCampaignStatus::FAILED, $campaign->status);
        $this->assertSame(1, $campaign->deliveries()->where('delivery_status', NewsletterCampaignDeliveryStatus::SENT)->count());
    }

    #[Test]
    public function an_empty_audience_is_rejected_without_starting_the_campaign(): void
    {
        $this->actingAsAdmin();
        $campaign = $this->campaign();

        $this->postJson("/api/v1/admin/newsletter-campaigns/{$campaign->id}/send-now")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('campaign');
        $this->assertSame(NewsletterCampaignStatus::DRAFT, $campaign->fresh()->status);
        $this->assertDatabaseCount('newsletter_campaign_deliveries', 0);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'internal_name' => 'Newsletter settembre',
            'subject' => 'Newsletter di prova',
            'preheader' => 'Anteprima della newsletter',
            'content' => "Prima riga\nSeconda riga",
            ...$overrides,
        ];
    }

    private function campaign(): NewsletterCampaign
    {
        return NewsletterCampaign::query()->create([
            ...$this->payload(),
            'status' => NewsletterCampaignStatus::DRAFT,
            'created_by' => 1,
            'updated_by' => 1,
        ]);
    }

    private function subscriber(string $email, NewsletterSubscriberStatus $status): NewsletterSubscriber
    {
        return NewsletterSubscriber::query()->create([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'status' => $status,
            'consent_version' => 'newsletter-marketing-v1',
            'consent_requested_at' => now()->subDay(),
            'confirmed_at' => $status === NewsletterSubscriberStatus::SUBSCRIBED ? now()->subHour() : null,
            'unsubscribed_at' => $status === NewsletterSubscriberStatus::UNSUBSCRIBED ? now()->subHour() : null,
        ]);
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
