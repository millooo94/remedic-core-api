<?php

namespace App\Services;

use App\Jobs\SendMarketingCampaignJob;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignDelivery;
use App\Models\MarketingSegment;
use App\Models\MarketingSegmentManualRecipient;
use App\Models\Patient;
use App\Models\User;
use App\Services\Marketing\MarketingChannelManager;
use App\Services\Marketing\PatientSegmentQueryService;
use App\Services\Marketing\Channels\MarketingChannel;
use App\Services\Marketing\Channels\MarketingChannelSendResult;
use App\Services\Marketing\WhatsAppPuppeteerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MarketingCampaignService
{
    public function __construct(
        private readonly MarketingChannelManager $channelManager,
        private readonly PatientSegmentQueryService $segmentQueryService,
        private readonly WhatsAppPuppeteerService $whatsAppPuppeteerService,
    ) {
    }

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

            $this->syncWhatsAppImage($campaign, $payload);

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
            $this->syncWhatsAppImage($campaign, $payload);

            return $campaign->refresh()->load(['segment', 'creator', 'launcher']);
        });
    }

    public function delete(MarketingCampaign $campaign): void
    {
        $this->deleteCampaignImage($campaign);
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
        $this->ensureWhatsAppReadyForInteractiveAction($campaign->channel);

        $resolvedChannelKey = $campaign->channel === 'all'
            ? ($campaign->whatsapp_image_path ? 'whatsapp' : 'sms')
            : $campaign->channel;
        $channel = $this->channelManager->for($resolvedChannelKey);

        $result = $channel->send(
            target: $target,
            message: $campaign->message,
            subject: $campaign->subject ?: $campaign->name,
            context: $this->channelContext($campaign, $resolvedChannelKey),
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
        $this->ensureWhatsAppReadyForInteractiveAction($campaign->channel);

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
                                context: $this->channelContext($campaign, $channelItem->key()),
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
                                context: $this->channelContext($campaign, $channelItem->key()),
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
                $this->channelManager->for('whatsapp'),
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncWhatsAppImage(MarketingCampaign $campaign, array $payload): void
    {
        $channelIncludesWhatsApp = in_array((string) ($campaign->channel ?? ''), ['whatsapp', 'all'], true);
        $removeRequested = (bool) ($payload['remove_whatsapp_image'] ?? false);
        /** @var UploadedFile|null $uploadedImage */
        $uploadedImage = $payload['whatsapp_image'] ?? null;

        if (! $channelIncludesWhatsApp) {
            $this->clearCampaignImage($campaign);

            return;
        }

        if ($removeRequested) {
            $this->clearCampaignImage($campaign);
        }

        if (! $uploadedImage instanceof UploadedFile) {
            return;
        }

        $this->deleteCampaignImage($campaign);
        $storedPath = $uploadedImage->store('marketing-campaigns', 'public');

        $campaign->forceFill([
            'whatsapp_image_path' => $storedPath,
            'whatsapp_image_original_name' => $uploadedImage->getClientOriginalName(),
            'whatsapp_image_mime_type' => $uploadedImage->getMimeType(),
            'whatsapp_image_size' => $uploadedImage->getSize(),
        ])->save();
    }

    private function clearCampaignImage(MarketingCampaign $campaign): void
    {
        $this->deleteCampaignImage($campaign);

        $campaign->forceFill([
            'whatsapp_image_path' => null,
            'whatsapp_image_original_name' => null,
            'whatsapp_image_mime_type' => null,
            'whatsapp_image_size' => null,
        ])->save();
    }

    private function deleteCampaignImage(MarketingCampaign $campaign): void
    {
        $path = $campaign->whatsapp_image_path;
        if (! $path) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelContext(MarketingCampaign $campaign, string $channelKey): array
    {
        if ($channelKey !== 'whatsapp' || ! $campaign->whatsapp_image_path) {
            return [];
        }

        $absolutePath = Storage::disk('public')->path($campaign->whatsapp_image_path);
        $mediaBase64 = null;

        if (Storage::disk('public')->exists($campaign->whatsapp_image_path)) {
            $mediaBase64 = base64_encode((string) Storage::disk('public')->get($campaign->whatsapp_image_path));
        }

        return [
            'media_path' => $absolutePath,
            'media_base64' => $mediaBase64,
            'media_name' => $campaign->whatsapp_image_original_name,
            'media_mime_type' => $campaign->whatsapp_image_mime_type,
        ];
    }

    private function ensureWhatsAppReadyForInteractiveAction(string $channel): void
    {
        if (! in_array($channel, ['whatsapp', 'all'], true)) {
            return;
        }

        $status = $this->whatsAppPuppeteerService->status();
        if (($status['ready'] ?? false) === true) {
            return;
        }

        throw ValidationException::withMessages([
            'channel' => [
                (string) ($status['message'] ?? 'WhatsApp non pronto all\'invio.'),
            ],
        ]);
    }

    private function manualRecipientEligibleForChannel(MarketingSegmentManualRecipient $recipient, string $channel): bool
    {
        if ($channel === 'all') {
            return collect(['sms', 'whatsapp', 'email'])
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

        if ($patient) {
            $isContactable = $channelKey === 'sms'
                ? (bool) $patient->contactable_sms
                : (bool) $patient->contactable_whatsapp;

            if (! $isContactable) {
                return [
                    'can_contact' => false,
                    'target' => $target,
                    'reason' => 'Canale non disponibile o contatto mancante.',
                ];
            }
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
