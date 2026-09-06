<?php

namespace App\Services;

use App\Enums\NewsletterCampaignDeliveryStatus;
use App\Enums\NewsletterCampaignStatus;
use App\Enums\NewsletterSubscriberStatus;
use App\Jobs\SendNewsletterCampaignDeliveryJob;
use App\Mail\NewsletterCampaignMail;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterCampaignDelivery;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class NewsletterCampaignService
{
    public function baseQuery(array $filters = []): Builder
    {
        return NewsletterCampaign::query()
            ->with(['creator', 'updater', 'launcher'])
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->where(function (Builder $nested) use ($q): void {
                $nested->where('internal_name', 'like', "%{$q}%")->orWhere('subject', 'like', "%{$q}%");
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc(DB::raw('COALESCE(sending_started_at, scheduled_at, created_at)'))
            ->orderByDesc('id');
    }

    public function create(array $payload, User $actor): NewsletterCampaign
    {
        return NewsletterCampaign::query()->create([
            'internal_name' => trim($payload['internal_name']),
            'subject' => trim($payload['subject']),
            'preheader' => $this->nullableTrimmedString($payload['preheader'] ?? null),
            'content' => trim($payload['content']),
            'status' => NewsletterCampaignStatus::DRAFT,
            'scheduled_at' => $this->nullableDateTime($payload['scheduled_at'] ?? null),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->load(['creator', 'updater', 'launcher']);
    }

    public function update(NewsletterCampaign $campaign, array $payload, User $actor): NewsletterCampaign
    {
        $this->ensureMutable($campaign);

        $scheduledAt = array_key_exists('scheduled_at', $payload)
            ? $this->nullableDateTime($payload['scheduled_at'])
            : $campaign->scheduled_at;
        if ($campaign->status === NewsletterCampaignStatus::SCHEDULED && (! $scheduledAt || ! $scheduledAt->isFuture())) {
            throw ValidationException::withMessages(['scheduled_at' => ['Una campagna programmata richiede una data futura.']]);
        }

        $campaign->fill([
            'internal_name' => trim($payload['internal_name']),
            'subject' => trim($payload['subject']),
            'preheader' => $this->nullableTrimmedString($payload['preheader'] ?? null),
            'content' => trim($payload['content']),
            'scheduled_at' => $scheduledAt,
            'updated_by' => $actor->id,
        ])->save();

        return $campaign->refresh()->load(['creator', 'updater', 'launcher']);
    }

    public function delete(NewsletterCampaign $campaign): void
    {
        if ($campaign->status !== NewsletterCampaignStatus::DRAFT) {
            throw ValidationException::withMessages(['campaign' => ['Solo una bozza può essere eliminata.']]);
        }

        $campaign->delete();
    }

    public function sendTest(NewsletterCampaign $campaign, string $email, User $actor): void
    {
        Mail::to(mb_strtolower(trim($email)))->send(new NewsletterCampaignMail(
            subjectLine: $campaign->subject,
            preheader: $campaign->preheader,
            bodyText: $campaign->content,
            isTest: true,
        ));

        $campaign->forceFill(['last_test_sent_at' => now(), 'updated_by' => $actor->id])->save();
    }

    public function sendNow(NewsletterCampaign $campaign, User $actor): NewsletterCampaign
    {
        return $this->beginDelivery($campaign->id, $actor, false);
    }

    public function schedule(NewsletterCampaign $campaign, string $scheduledAt, User $actor): NewsletterCampaign
    {
        $this->ensureMutable($campaign);
        $when = $this->requiredFutureDateTime($scheduledAt);

        $campaign->forceFill([
            'status' => NewsletterCampaignStatus::SCHEDULED,
            'scheduled_at' => $when,
            'updated_by' => $actor->id,
            'launched_by' => $actor->id,
        ])->save();

        return $campaign->refresh()->load(['creator', 'updater', 'launcher']);
    }

    public function cancelSchedule(NewsletterCampaign $campaign, User $actor): NewsletterCampaign
    {
        if ($campaign->status !== NewsletterCampaignStatus::SCHEDULED) {
            throw ValidationException::withMessages(['campaign' => ['La campagna non è programmata.']]);
        }

        $campaign->forceFill([
            'status' => NewsletterCampaignStatus::DRAFT,
            'scheduled_at' => null,
            'updated_by' => $actor->id,
        ])->save();

        return $campaign->refresh()->load(['creator', 'updater', 'launcher']);
    }

    public function dispatchScheduledCampaigns(): int
    {
        $ids = NewsletterCampaign::query()
            ->where('status', NewsletterCampaignStatus::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->pluck('id');

        $started = 0;
        foreach ($ids as $id) {
            try {
                $this->beginDelivery((int) $id, null, true);
                $started++;
            } catch (ValidationException) {
                // Another scheduler or operator has already changed this campaign's state.
            }
        }

        return $started;
    }

    public function processDelivery(int $deliveryId): void
    {
        /** @var NewsletterCampaignDelivery|null $delivery */
        $delivery = DB::transaction(function () use ($deliveryId): ?NewsletterCampaignDelivery {
            $delivery = NewsletterCampaignDelivery::query()->with(['campaign', 'subscriber'])->lockForUpdate()->find($deliveryId);
            if (! $delivery || ! in_array($delivery->delivery_status, [NewsletterCampaignDeliveryStatus::PENDING, NewsletterCampaignDeliveryStatus::SENDING], true)) {
                return null;
            }

            if ($delivery->subscriber->status !== NewsletterSubscriberStatus::SUBSCRIBED) {
                $delivery->forceFill([
                    'delivery_status' => NewsletterCampaignDeliveryStatus::SUPPRESSED,
                    'error_message' => 'Invio soppresso: iscrizione newsletter non più attiva.',
                    'suppressed_at' => now(),
                ])->save();
                $this->completeCampaignIfFinished($delivery->newsletter_campaign_id);

                return null;
            }

            $delivery->forceFill(['delivery_status' => NewsletterCampaignDeliveryStatus::SENDING])->save();

            return $delivery;
        });

        if (! $delivery) {
            return;
        }

        Mail::to($delivery->email_snapshot)->send(new NewsletterCampaignMail(
            subjectLine: $delivery->campaign->subject,
            preheader: $delivery->campaign->preheader,
            bodyText: $delivery->campaign->content,
            unsubscribeUrl: URL::signedRoute('newsletter.unsubscribe', ['publicId' => $delivery->subscriber->public_id, 'locale' => 'it']),
        ));

        DB::transaction(function () use ($deliveryId): void {
            $delivery = NewsletterCampaignDelivery::query()->lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->delivery_status !== NewsletterCampaignDeliveryStatus::SENDING) {
                return;
            }

            $delivery->forceFill([
                'delivery_status' => NewsletterCampaignDeliveryStatus::SENT,
                'sent_at' => now(),
            ])->save();
            $this->completeCampaignIfFinished($delivery->newsletter_campaign_id);
        });
    }

    public function markDeliveryFailed(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = NewsletterCampaignDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery || ! in_array($delivery->delivery_status, [NewsletterCampaignDeliveryStatus::PENDING, NewsletterCampaignDeliveryStatus::SENDING], true)) {
                return;
            }

            $delivery->forceFill([
                'delivery_status' => NewsletterCampaignDeliveryStatus::FAILED,
                'error_message' => 'Errore tecnico durante l’invio dell’email.',
                'failed_at' => now(),
            ])->save();
            $this->completeCampaignIfFinished($delivery->newsletter_campaign_id);
        });
    }

    private function beginDelivery(int $campaignId, ?User $actor, bool $fromScheduler): NewsletterCampaign
    {
        return DB::transaction(function () use ($campaignId, $actor, $fromScheduler): NewsletterCampaign {
            $campaign = NewsletterCampaign::query()->lockForUpdate()->findOrFail($campaignId);
            $allowedStatus = $fromScheduler
                ? $campaign->status === NewsletterCampaignStatus::SCHEDULED
                : in_array($campaign->status, [NewsletterCampaignStatus::DRAFT, NewsletterCampaignStatus::SCHEDULED], true);
            if (! $allowedStatus) {
                throw ValidationException::withMessages(['campaign' => ['La campagna è già stata avviata o non è inviabile.']]);
            }

            $subscribers = NewsletterSubscriber::query()
                ->where('status', NewsletterSubscriberStatus::SUBSCRIBED)
                ->orderBy('id')
                ->get(['id', 'email']);
            if ($subscribers->isEmpty()) {
                throw ValidationException::withMessages(['campaign' => ['Non ci sono iscritti confermati a cui inviare la newsletter.']]);
            }

            $now = now();
            $campaign->deliveries()->createMany($subscribers->map(fn (NewsletterSubscriber $subscriber): array => [
                'newsletter_subscriber_id' => $subscriber->id,
                'email_snapshot' => $subscriber->email,
                'delivery_status' => NewsletterCampaignDeliveryStatus::PENDING,
                'queued_at' => $now,
            ])->all());

            $campaign->forceFill([
                'status' => NewsletterCampaignStatus::SENDING,
                'scheduled_at' => $fromScheduler ? $campaign->scheduled_at : null,
                'sending_started_at' => $now,
                'sent_at' => null,
                'recipient_count' => $subscribers->count(),
                'sent_count' => 0,
                'failed_count' => 0,
                'suppressed_count' => 0,
                'launched_by' => $actor?->id ?? $campaign->launched_by,
                'updated_by' => $actor?->id ?? $campaign->updated_by,
            ])->save();

            foreach ($campaign->deliveries()->pluck('id') as $deliveryId) {
                SendNewsletterCampaignDeliveryJob::dispatch((int) $deliveryId)->afterCommit();
            }

            return $campaign->refresh()->load(['creator', 'updater', 'launcher']);
        });
    }

    private function completeCampaignIfFinished(int $campaignId): void
    {
        $campaign = NewsletterCampaign::query()->lockForUpdate()->findOrFail($campaignId);
        $pending = $campaign->deliveries()->whereIn('delivery_status', [NewsletterCampaignDeliveryStatus::PENDING, NewsletterCampaignDeliveryStatus::SENDING])->exists();
        $sent = $campaign->deliveries()->where('delivery_status', NewsletterCampaignDeliveryStatus::SENT)->count();
        $failed = $campaign->deliveries()->where('delivery_status', NewsletterCampaignDeliveryStatus::FAILED)->count();
        $suppressed = $campaign->deliveries()->where('delivery_status', NewsletterCampaignDeliveryStatus::SUPPRESSED)->count();

        $campaign->forceFill([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'suppressed_count' => $suppressed,
            'status' => $pending ? NewsletterCampaignStatus::SENDING : ($failed > 0 ? NewsletterCampaignStatus::FAILED : NewsletterCampaignStatus::SENT),
            'sent_at' => $pending ? null : now(),
        ])->save();
    }

    private function ensureMutable(NewsletterCampaign $campaign): void
    {
        if (! in_array($campaign->status, [NewsletterCampaignStatus::DRAFT, NewsletterCampaignStatus::SCHEDULED], true)) {
            throw ValidationException::withMessages(['campaign' => ['La campagna non è più modificabile dopo l’avvio.']]);
        }
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableDateTime(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function requiredFutureDateTime(string $value): Carbon
    {
        $date = $this->nullableDateTime($value);
        if (! $date || ! $date->isFuture()) {
            throw ValidationException::withMessages(['scheduled_at' => ['La data di invio deve essere futura.']]);
        }

        return $date;
    }
}
