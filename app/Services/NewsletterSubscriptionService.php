<?php

namespace App\Services;

use App\Enums\NewsletterConsentEventType;
use App\Enums\NewsletterSubscriberStatus;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NewsletterSubscriptionService
{
    /**
     * @return array{subscriber: NewsletterSubscriber, token: string|null}
     */
    public function requestSubscription(string $email): array
    {
        $email = $this->normalizeEmail($email);

        return DB::transaction(function () use ($email): array {
            /** @var NewsletterSubscriber|null $subscriber */
            $subscriber = NewsletterSubscriber::query()->where('email', $email)->lockForUpdate()->first();

            if ($subscriber?->status === NewsletterSubscriberStatus::SUBSCRIBED) {
                return ['subscriber' => $subscriber, 'token' => null];
            }

            if ($subscriber?->status === NewsletterSubscriberStatus::PENDING
                && $subscriber->confirmation_sent_at?->gt(now()->subMinutes($this->resendCooldownMinutes()))) {
                return ['subscriber' => $subscriber, 'token' => null];
            }

            $token = bin2hex(random_bytes(32));
            $now = now();
            $values = [
                'status' => NewsletterSubscriberStatus::PENDING,
                'consent_version' => $this->consentVersion(),
                'consent_requested_at' => $now,
                'confirmation_token_hash' => hash('sha256', $token),
                'confirmation_expires_at' => $now->copy()->addHours($this->confirmationTtlHours()),
                'confirmation_sent_at' => $now,
            ];

            if ($subscriber === null) {
                $subscriber = NewsletterSubscriber::query()->create([
                    ...$values,
                    'public_id' => (string) Str::uuid(),
                    'email' => $email,
                ]);
            } else {
                $subscriber->fill($values)->save();
            }

            $subscriber->consentEvents()->create([
                'event_type' => NewsletterConsentEventType::SUBSCRIPTION_REQUESTED,
                'consent_version' => $this->consentVersion(),
                'occurred_at' => $now,
            ]);

            return ['subscriber' => $subscriber, 'token' => $token];
        });
    }

    public function confirm(string $token): ?NewsletterSubscriber
    {
        return DB::transaction(function () use ($token): ?NewsletterSubscriber {
            $subscriber = NewsletterSubscriber::query()
                ->where('confirmation_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($subscriber === null
                || $subscriber->status !== NewsletterSubscriberStatus::PENDING
                || $subscriber->confirmation_expires_at === null
                || $subscriber->confirmation_expires_at->isPast()) {
                return null;
            }

            $now = now();
            $subscriber->fill([
                'status' => NewsletterSubscriberStatus::SUBSCRIBED,
                'confirmation_token_hash' => null,
                'confirmation_expires_at' => null,
                'confirmed_at' => $now,
            ])->save();
            $subscriber->consentEvents()->create([
                'event_type' => NewsletterConsentEventType::SUBSCRIPTION_CONFIRMED,
                'consent_version' => $subscriber->consent_version,
                'occurred_at' => $now,
            ]);

            return $subscriber;
        });
    }

    public function unsubscribe(NewsletterSubscriber $subscriber): NewsletterSubscriber
    {
        return DB::transaction(function () use ($subscriber): NewsletterSubscriber {
            $subscriber->refresh();

            if ($subscriber->status === NewsletterSubscriberStatus::UNSUBSCRIBED) {
                return $subscriber;
            }

            $now = now();
            $subscriber->fill([
                'status' => NewsletterSubscriberStatus::UNSUBSCRIBED,
                'confirmation_token_hash' => null,
                'confirmation_expires_at' => null,
                'unsubscribed_at' => $now,
            ])->save();
            $subscriber->consentEvents()->create([
                'event_type' => NewsletterConsentEventType::UNSUBSCRIBED,
                'consent_version' => $subscriber->consent_version,
                'occurred_at' => $now,
            ]);

            return $subscriber;
        });
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function consentVersion(): string
    {
        return (string) config('newsletter.consent_version');
    }

    private function confirmationTtlHours(): int
    {
        return max(1, (int) config('newsletter.confirmation_ttl_hours', 48));
    }

    private function resendCooldownMinutes(): int
    {
        return max(1, (int) config('newsletter.resend_cooldown_minutes', 5));
    }
}
