<?php

namespace App\Services;

use App\Jobs\SendMarketingCampaignJob;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignDelivery;
use App\Models\MarketingSegment;
use App\Models\MarketingSegmentManualRecipient;
use App\Models\Patient;
use App\Models\User;
use App\Services\Marketing\Channels\MarketingChannel;
use App\Services\Marketing\Channels\MarketingChannelSendResult;
use App\Services\Marketing\MarketingChannelManager;
use App\Services\Marketing\PatientSegmentQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MarketingCampaignService
{
    public function __construct(
        private readonly MarketingChannelManager $channelManager,
        private readonly PatientSegmentQueryService $segmentQueryService,
    ) {}

    public function baseQuery(array $filters = []): Builder
    {
        return MarketingCampaign::query()
            ->with(['segment', 'creator', 'launcher'])
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            })
            ->when($filters['channel'] ?? null, fn (Builder $builder, string $channel) => $builder->where('channel', $channel))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status))
            ->when($filters['history_only'] ?? false, fn (Builder $builder) => $builder->whereNotIn('status', ['draft']))
            ->orderByDesc(DB::raw('COALESCE(dispatched_at, scheduled_at, created_at)'))
            ->orderByDesc('id');
    }

    public function create(array $payload, User $actor): MarketingCampaign
    {
        return DB::transaction(function () use ($payload, $actor): MarketingCampaign {
            $segment = MarketingSegment::query()->findOrFail($payload['marketing_segment_id']);
            $preview = $this->previewForSegment($segment, (string) $payload['channel']);

            $campaign = MarketingCampaign::query()->create([
                'name' => trim((string) $payload['name']),
                'marketing_segment_id' => $segment->id,
                'channel' => $payload['channel'],
                'template_key' => $this->nullableTrimmedString($payload['template_key'] ?? null),
                'subject' => $this->nullableTrimmedString($payload['subject'] ?? null),
                'message' => trim((string) $payload['message']),
                'status' => 'draft',
                'scheduled_at' => $this->nullableDateTime($payload['scheduled_at'] ?? null),
                'recipients_count' => $preview['segment_patients'],
                'excluded_count' => $preview['excluded'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return $campaign->load(['segment', 'creator', 'launcher']);
        });
    }

    public function update(MarketingCampaign $campaign, array $payload, User $actor): MarketingCampaign
    {
        return DB::transaction(function () use ($campaign, $payload, $actor): MarketingCampaign {
            $segment = MarketingSegment::query()->findOrFail($payload['marketing_segment_id']);
            $preview = $this->previewForSegment($segment, (string) $payload['channel']);

            $campaign->fill([
                'name' => trim((string) $payload['name']),
                'marketing_segment_id' => $segment->id,
                'channel' => $payload['channel'],
                'template_key' => $this->nullableTrimmedString($payload['template_key'] ?? null),
                'subject' => $this->nullableTrimmedString($payload['subject'] ?? null),
                'message' => trim((string) $payload['message']),
                'scheduled_at' => $this->nullableDateTime($payload['scheduled_at'] ?? null),
                'recipients_count' => $preview['segment_patients'],
                'excluded_count' => $preview['excluded'],
                'updated_by' => $actor->id,
            ]);

            if ($campaign->status === 'failed' || $campaign->status === 'partial_failed' || $campaign->status === 'sent') {
                $campaign->status = 'draft';
                $campaign->sent_count = 0;
                $campaign->failed_count = 0;
                $campaign->completed_at = null;
                $campaign->dispatched_at = null;
            }

            $campaign->save();

            return $campaign->refresh()->load(['segment', 'creator', 'launcher']);
        });
    }

    public function delete(MarketingCampaign $campaign): void
    {
        $campaign->delete();
    }

    public function previewForSegment(MarketingSegment $segment, string $channel): array
    {
        if (($segment->segment_type ?? 'filter_based') === 'manual') {
            $manualRecipients = $segment->manualRecipients()->with('patient')->get();
            $eligibleRecipients = $manualRecipients
                ->filter(fn (MarketingSegmentManualRecipient $recipient) => $this->manualRecipientEligibleForChannel($recipient, $channel))
                ->count();

            return [
                'segment_patients' => $manualRecipients->count(),
                'eligible_recipients' => $eligibleRecipients,
                'excluded' => max(0, $manualRecipients->count() - $eligibleRecipients),
            ];
        }

        $baseQuery = $this->segmentQueryService->applySegmentFilters(Patient::query(), $segment->filters ?? []);
        $segmentPatients = (clone $baseQuery)->count();
        $eligibleRecipients = $this->segmentQueryService->applyChannelEligibility(clone $baseQuery, $channel)->count();

        return [
            'segment_patients' => $segmentPatients,
            'eligible_recipients' => $eligibleRecipients,
            'excluded' => max(0, $segmentPatients - $eligibleRecipients),
        ];
    }

    public function sendTest(MarketingCampaign $campaign, string $target, User $actor): MarketingCampaignDelivery
    {
        $this->ensureSupportedCampaignChannel($campaign->channel);

        $resolvedChannelKey = $campaign->channel === 'all'
            ? 'sms'
            : $campaign->channel;
        $channel = $this->channelManager->for($resolvedChannelKey);

        $result = $channel->send(
            target: $target,
            message: $campaign->message,
            subject: $campaign->subject ?: $campaign->name,
            context: [],
        );

        $delivery = $campaign->deliveries()->create([
            'patient_id' => null,
            'channel' => $resolvedChannelKey,
            'is_test' => true,
            'target_name' => $actor->full_name,
            'target_value' => $target,
            'delivery_status' => $result->deliveryStatus,
            'provider_message_id' => $result->messageId,
            'provider_status' => $result->providerStatus,
            'error_message' => $result->errorMessage,
            'provider_response' => $result->response,
            'sent_at' => $result->sentAt,
        ]);

        $campaign->forceFill([
            'last_test_sent_at' => now(),
            'updated_by' => $actor->id,
        ])->save();

        return $delivery;
    }

    public function launch(MarketingCampaign $campaign, User $actor, ?string $scheduledAt = null): MarketingCampaign
    {
        $this->ensureSupportedCampaignChannel($campaign->channel);

        return DB::transaction(function () use ($campaign, $actor, $scheduledAt): MarketingCampaign {
            $campaign = $campaign->refresh()->loadMissing('segment');
            $preview = $this->previewForSegment($campaign->segment, $campaign->channel);

            $when = $this->nullableDateTime($scheduledAt) ?? $campaign->scheduled_at;
            $status = $when && $when->isFuture() ? 'scheduled' : 'queued';

            $campaign->forceFill([
                'status' => $status,
                'scheduled_at' => $when,
                'launched_by' => $actor->id,
                'updated_by' => $actor->id,
                'recipients_count' => $preview['segment_patients'],
                'excluded_count' => $preview['excluded'],
                'sent_count' => 0,
                'failed_count' => 0,
            ])->save();

            if ($status === 'queued') {
                SendMarketingCampaignJob::dispatch($campaign->id);
            }

            return $campaign->refresh()->load(['segment', 'creator', 'launcher']);
        });
    }

    public function processCampaign(MarketingCampaign $campaign): MarketingCampaign
    {
        $this->ensureSupportedCampaignChannel($campaign->channel);

        return DB::transaction(function () use ($campaign): MarketingCampaign {
            $campaign = $campaign->refresh()->loadMissing('segment');

            if (! $campaign->segment) {
                throw new RuntimeException('Segmento marketing mancante per la campagna.');
            }

            $campaign->forceFill([
                'status' => 'sending',
                'dispatched_at' => now(),
                'completed_at' => null,
            ])->save();

            $campaign->deliveries()->where('is_test', false)->delete();

            $sent = 0;
            $failed = 0;
            $excluded = 0;
            $channels = $this->channelsForCampaign($campaign->channel);

            if (($campaign->segment->segment_type ?? 'filter_based') === 'manual') {
                $recipients = $campaign->segment->manualRecipients()->with('patient')->get();

                foreach ($recipients as $recipient) {
                    foreach ($channels as $channelItem) {
                        $availability = $this->manualRecipientAvailability($recipient, $channelItem);
                        if (! $availability['can_contact']) {
                            $campaign->deliveries()->create([
                                'patient_id' => $recipient->patient_id,
                                'channel' => $channelItem->key(),
                                'is_test' => false,
                                'target_name' => $this->manualRecipientName($recipient),
                                'target_value' => $availability['target'] ?? $recipient->normalized_phone,
                                'delivery_status' => 'excluded',
                                'error_message' => $availability['reason'],
                            ]);
                            $excluded++;

                            continue;
                        }

                        try {
                            $result = $channelItem->send(
                                target: (string) $availability['target'],
                                message: $this->renderMessage($campaign->message, $recipient->patient),
                                subject: $this->renderMessage($campaign->subject ?: $campaign->name, $recipient->patient),
                                context: [],
                            );
                        } catch (\Throwable $exception) {
                            $result = MarketingChannelSendResult::failed(
                                providerStatus: 'technical_error',
                                errorMessage: 'Errore tecnico durante l\'invio sul canale selezionato.',
                                response: [
                                    'technical_message' => $exception->getMessage(),
                                ],
                            );
                        }

                        $campaign->deliveries()->create([
                            'patient_id' => $recipient->patient_id,
                            'channel' => $channelItem->key(),
                            'is_test' => false,
                            'target_name' => $this->manualRecipientName($recipient),
                            'target_value' => $availability['target'] ?? $recipient->normalized_phone,
                            'delivery_status' => $result->deliveryStatus,
                            'provider_message_id' => $result->messageId,
                            'provider_status' => $result->providerStatus,
                            'error_message' => $result->errorMessage,
                            'provider_response' => $result->response,
                            'sent_at' => $result->sentAt,
                        ]);

                        if ($result->deliveryStatus === 'sent') {
                            $sent++;
                        } elseif ($result->deliveryStatus === 'excluded') {
                            $excluded++;
                        } else {
                            $failed++;
                        }
                    }
                }

                $recipientsCount = $recipients->count();
            } else {
                $patients = $this->segmentQueryService
                    ->applySegmentFilters(Patient::query(), $campaign->segment->filters ?? [])
                    ->orderBy('patients.id')
                    ->get();

                /** @var Patient $patient */
                foreach ($patients as $patient) {
                    if ($patient->excluded_from_campaigns) {
                        foreach ($channels as $channelItem) {
                            $campaign->deliveries()->create([
                                'patient_id' => $patient->id,
                                'channel' => $channelItem->key(),
                                'is_test' => false,
                                'target_name' => $patient->full_name,
                                'target_value' => $channelItem->resolveTarget($patient) ?? '-',
                                'delivery_status' => 'excluded',
                                'error_message' => 'Paziente escluso localmente dalle campagne.',
                            ]);
                            $excluded++;
                        }

                        continue;
                    }

                    foreach ($channels as $channelItem) {
                        if (! $channelItem->canContact($patient)) {
                            $campaign->deliveries()->create([
                                'patient_id' => $patient->id,
                                'channel' => $channelItem->key(),
                                'is_test' => false,
                                'target_name' => $patient->full_name,
                                'target_value' => $channelItem->resolveTarget($patient) ?? '-',
                                'delivery_status' => 'excluded',
                                'error_message' => 'Canale non disponibile o contatto mancante.',
                            ]);
                            $excluded++;

                            continue;
                        }

                        try {
                            $target = $channelItem->resolveTarget($patient);
                            $result = $channelItem->send(
                                target: (string) $target,
                                message: $this->renderMessage($campaign->message, $patient),
                                subject: $this->renderMessage($campaign->subject ?: $campaign->name, $patient),
                                context: [],
                            );
                        } catch (\Throwable $exception) {
                            $target = $channelItem->resolveTarget($patient);
                            $result = MarketingChannelSendResult::failed(
                                providerStatus: 'technical_error',
                                errorMessage: 'Errore tecnico durante l\'invio sul canale selezionato.',
                                response: [
                                    'technical_message' => $exception->getMessage(),
                                ],
                            );
                        }

                        $campaign->deliveries()->create([
                            'patient_id' => $patient->id,
                            'channel' => $channelItem->key(),
                            'is_test' => false,
                            'target_name' => $patient->full_name,
                            'target_value' => $target ?? '-',
                            'delivery_status' => $result->deliveryStatus,
                            'provider_message_id' => $result->messageId,
                            'provider_status' => $result->providerStatus,
                            'error_message' => $result->errorMessage,
                            'provider_response' => $result->response,
                            'sent_at' => $result->sentAt,
                        ]);

                        if ($result->deliveryStatus === 'sent') {
                            $sent++;
                        } elseif ($result->deliveryStatus === 'excluded') {
                            $excluded++;
                        } else {
                            $failed++;
                        }
                    }
                }

                $recipientsCount = $patients->count();
            }

            $campaign->forceFill([
                'status' => $failed > 0 && $sent > 0 ? 'partial_failed' : ($failed > 0 ? 'failed' : 'sent'),
                'completed_at' => now(),
                'recipients_count' => $recipientsCount,
                'sent_count' => $sent,
                'failed_count' => $failed,
                'excluded_count' => $excluded,
            ])->save();

            return $campaign->refresh()->load(['segment', 'creator', 'launcher', 'deliveries.patient']);
        });
    }

    public function dispatchScheduledCampaigns(): int
    {
        $campaigns = MarketingCampaign::query()
            ->where('status', 'scheduled')
            ->whereIn('channel', ['sms', 'email', 'all'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($campaigns as $campaign) {
            $campaign->forceFill([
                'status' => 'queued',
            ])->save();

            SendMarketingCampaignJob::dispatch($campaign->id);
        }

        return $campaigns->count();
    }

    private function ensureSupportedCampaignChannel(string $channel): void
    {
        if (in_array($channel, ['sms', 'email', 'all'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'channel' => ['Il canale storico della campagna non è più supportato.'],
        ]);
    }

    private function renderMessage(string $template, ?Patient $patient): string
    {
        return strtr($template, [
            '{{nome}}' => $patient?->first_name ?? '',
            '{{cognome}}' => $patient?->last_name ?? '',
            '{{nome_completo}}' => $patient?->full_name ?? '',
            '{{data_nascita}}' => optional($patient?->birth_date)?->format('d/m/Y') ?? '',
            '{{anno_nascita}}' => (string) ($patient?->year_of_birth ?? ''),
        ]);
    }

    /**
     * @return MarketingChannel[]
     */
    private function channelsForCampaign(string $channel): array
    {
        if ($channel === 'all') {
            return [
                $this->channelManager->for('sms'),
                $this->channelManager->for('email'),
            ];
        }

        return [$this->channelManager->for($channel)];
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

    private function nullableTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function manualRecipientEligibleForChannel(MarketingSegmentManualRecipient $recipient, string $channel): bool
    {
        if ($channel === 'all') {
            return collect(['sms', 'email'])
                ->contains(fn (string $channelKey) => $this->manualRecipientEligibleForChannel($recipient, $channelKey));
        }

        return $this->manualRecipientAvailability(
            $recipient,
            $this->channelManager->for($channel),
        )['can_contact'];
    }

    /**
     * @return array{can_contact:bool,target:?string,reason:?string}
     */
    private function manualRecipientAvailability(MarketingSegmentManualRecipient $recipient, MarketingChannel $channel): array
    {
        $patient = $recipient->patient;
        $channelKey = $channel->key();

        if ($patient?->excluded_from_campaigns) {
            return [
                'can_contact' => false,
                'target' => $recipient->normalized_phone,
                'reason' => 'Paziente escluso localmente dalle campagne.',
            ];
        }

        if ($channelKey === 'email') {
            $target = $patient ? $channel->resolveTarget($patient) : null;

            if (! $patient || ! $patient->contactable_email || ! $target) {
                return [
                    'can_contact' => false,
                    'target' => $target,
                    'reason' => 'Canale email non disponibile per questo contatto manuale.',
                ];
            }

            return [
                'can_contact' => true,
                'target' => $target,
                'reason' => null,
            ];
        }

        $target = $recipient->normalized_phone;
        if ($target === '') {
            return [
                'can_contact' => false,
                'target' => null,
                'reason' => 'Numero manuale non disponibile.',
            ];
        }

        if ($patient && ! $patient->contactable_sms) {
            return [
                'can_contact' => false,
                'target' => $target,
                'reason' => 'Canale non disponibile o contatto mancante.',
            ];
        }

        return [
            'can_contact' => true,
            'target' => $target,
            'reason' => null,
        ];
    }

    private function manualRecipientName(MarketingSegmentManualRecipient $recipient): string
    {
        return $recipient->patient?->full_name
            ?? $recipient->original_value
            ?? $recipient->normalized_phone;
    }
}
