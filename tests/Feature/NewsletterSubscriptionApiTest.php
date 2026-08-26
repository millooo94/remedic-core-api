<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\NewsletterConsentEventType;
use App\Enums\NewsletterSubscriberStatus;
use App\Enums\UserRole;
use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NewsletterSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        Mail::fake();
    }

    #[Test]
    public function subscription_uses_a_generic_response_and_persists_only_a_hash(): void
    {
        $response = $this->postJson('/api/v1/public/newsletter/subscribe', [
            'email' => '  Persona@Example.test ',
            'consent_accepted' => true,
        ])->assertAccepted();

        $response->assertJsonPath('message', 'Se l’indirizzo può essere iscritto, riceverai a breve un’email di conferma.');
        $subscriber = NewsletterSubscriber::query()->firstOrFail();
        $this->assertSame('persona@example.test', $subscriber->email);
        $this->assertSame(NewsletterSubscriberStatus::PENDING, $subscriber->status);
        $this->assertNotNull($subscriber->confirmation_token_hash);
        $this->assertSame(64, strlen((string) $subscriber->confirmation_token_hash));
        $this->assertDatabaseHas('newsletter_consent_events', ['event_type' => NewsletterConsentEventType::SUBSCRIPTION_REQUESTED->value]);
        Mail::assertSent(NewsletterConfirmationMail::class, fn (NewsletterConfirmationMail $mail): bool => str_contains($mail->confirmationUrl, 'newsletter/confirm?token='));
    }

    #[Test]
    public function subscription_validates_email_and_explicit_consent(): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', ['email' => 'invalid', 'consent_accepted' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'consent_accepted']);
    }

    #[Test]
    public function pending_resend_is_cooled_down_and_a_later_resend_rotates_the_token(): void
    {
        $this->subscribe('persona@example.test');
        $subscriber = NewsletterSubscriber::query()->firstOrFail();
        $firstHash = $subscriber->confirmation_token_hash;
        $this->subscribe('persona@example.test');
        Mail::assertSent(NewsletterConfirmationMail::class, 1);
        $this->assertSame($firstHash, $subscriber->fresh()->confirmation_token_hash);

        $subscriber->update(['confirmation_sent_at' => now()->subMinutes(6)]);
        $this->subscribe('persona@example.test');
        Mail::assertSent(NewsletterConfirmationMail::class, 2);
        $this->assertNotSame($firstHash, $subscriber->fresh()->confirmation_token_hash);
        $this->assertSame(2, $subscriber->consentEvents()->count());
    }

    #[Test]
    public function confirmation_transitions_pending_subscription_and_rejects_expired_or_reused_tokens(): void
    {
        $this->subscribe('persona@example.test');
        $token = $this->sentToken();
        $this->getJson('/api/v1/public/newsletter/confirm?token='.$token)->assertOk()->assertJsonPath('data.status', 'subscribed');
        $subscriber = NewsletterSubscriber::query()->firstOrFail();
        $this->assertSame(NewsletterSubscriberStatus::SUBSCRIBED, $subscriber->status);
        $this->assertNull($subscriber->confirmation_token_hash);
        $this->assertDatabaseHas('newsletter_consent_events', ['event_type' => NewsletterConsentEventType::SUBSCRIPTION_CONFIRMED->value]);
        $this->getJson('/api/v1/public/newsletter/confirm?token='.$token)->assertUnprocessable();

        $this->subscribe('expired@example.test');
        $expiredToken = $this->sentToken(1);
        NewsletterSubscriber::query()->where('email', 'expired@example.test')->update(['confirmation_expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/public/newsletter/confirm?token='.$expiredToken)->assertUnprocessable();
    }

    #[Test]
    public function signed_unsubscribe_is_idempotent_and_a_new_request_can_resubscribe(): void
    {
        $this->subscribe('persona@example.test');
        $this->getJson('/api/v1/public/newsletter/confirm?token='.$this->sentToken())->assertOk();
        $subscriber = NewsletterSubscriber::query()->firstOrFail();
        $url = URL::signedRoute('newsletter.unsubscribe', ['publicId' => $subscriber->public_id]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'unsubscribed');
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'unsubscribed');
        $this->assertSame(1, $subscriber->fresh()->consentEvents()->where('event_type', NewsletterConsentEventType::UNSUBSCRIBED->value)->count());
        $this->getJson('/api/v1/public/newsletter/unsubscribe/'.$subscriber->public_id)->assertForbidden();

        $this->subscribe('persona@example.test');
        $this->assertSame(NewsletterSubscriberStatus::PENDING, $subscriber->fresh()->status);
        Mail::assertSent(NewsletterConfirmationMail::class, 2);
    }

    #[Test]
    public function admin_listing_and_detail_are_read_only_and_permission_protected(): void
    {
        $subscriber = NewsletterSubscriber::query()->create([
            'public_id' => 'a6fbb94d-85d8-44f4-941e-8be4ab29db7a',
            'email' => 'persona@example.test',
            'status' => NewsletterSubscriberStatus::SUBSCRIBED,
            'consent_version' => 'newsletter-marketing-v1',
            'consent_requested_at' => now()->subDay(),
            'confirmed_at' => now(),
        ]);
        $subscriber->consentEvents()->create(['event_type' => NewsletterConsentEventType::SUBSCRIPTION_CONFIRMED, 'consent_version' => 'newsletter-marketing-v1', 'occurred_at' => now()]);

        $this->getJson('/api/v1/admin/newsletter-subscribers')->assertUnauthorized();
        $this->actingAsWebAdmin();
        $this->getJson('/api/v1/admin/newsletter-subscribers?status=subscribed&q=persona')->assertOk()
            ->assertJsonPath('data.0.email', 'persona@example.test')
            ->assertJsonMissingPath('data.0.confirmation_token_hash');
        $this->getJson('/api/v1/admin/newsletter-subscribers/'.$subscriber->public_id)->assertOk()
            ->assertJsonPath('data.events.0.event_type', 'subscription_confirmed')
            ->assertJsonMissingPath('data.confirmation_token_hash');
        $this->postJson('/api/v1/admin/newsletter-subscribers')->assertStatus(405);
    }

    private function subscribe(string $email): void
    {
        $this->postJson('/api/v1/public/newsletter/subscribe', ['email' => $email, 'consent_accepted' => true])->assertAccepted();
    }

    private function sentToken(int $mailIndex = 0): string
    {
        $mails = Mail::sent(NewsletterConfirmationMail::class);
        $mail = $mails[$mailIndex] instanceof NewsletterConfirmationMail ? $mails[$mailIndex] : $mails[$mailIndex]->getMailable();
        parse_str((string) parse_url($mail->confirmationUrl, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
