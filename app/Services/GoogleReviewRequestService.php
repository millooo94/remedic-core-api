<?php

namespace App\Services;

use App\Enums\VisitShift;
use App\Models\ExternalProviderAccount;
use App\Models\GoogleReviewRequest;
use App\Models\PerformanceRecord;
use App\Models\SiteSetting;
use App\Models\Specialization;
use App\Models\User;
use App\Services\Marketing\MarketingContactNormalizer;
use App\Services\Marketing\WhatsAppPuppeteerService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleReviewRequestService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ERROR = 'error';

    private const WHATSAPP_PROVIDER = 'whatsapp';
    private const BUSINESS_TIMEZONE = 'Europe/Rome';
    private const AUTO_SEND_START_HOUR = 10;
    private const AUTO_SEND_START_MINUTE = 0;
    private const AUTO_SEND_END_HOUR = 19;
    private const AUTO_SEND_END_MINUTE = 0;

    public function __construct(
        private readonly MarketingContactNormalizer $contactNormalizer,
        private readonly WhatsAppPuppeteerService $whatsAppPuppeteerService,
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = GoogleReviewRequest::query()
            ->with(['performanceRecord', 'patient', 'professional'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($status = trim((string) ($filters['status'] ?? ''))) {
            $query->where('status', $status);
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('patient_name', 'like', "%{$search}%")
                    ->orWhere('patient_phone', 'like', "%{$search}%")
                    ->orWhere('professional_name', 'like', "%{$search}%")
                    ->orWhere('specialization_name', 'like', "%{$search}%");
            });
        }

        if ($dateFrom = Arr::get($filters, 'date_from')) {
            $query->whereDate('scheduled_at', '>=', $dateFrom);
        }

        if ($dateTo = Arr::get($filters, 'date_to')) {
            $query->whereDate('scheduled_at', '<=', $dateTo);
        }

        return $query->paginate($perPage);
    }

    public function settings(): array
    {
        $settings = SiteSetting::singleton();
        $account = $this->whatsAppAccount();
        $config = $account?->config_json ?? [];

        return [
            'google_review_url' => $settings->google_review_url,
            'whatsapp_template_name' => Arr::get($config, 'review_template_name'),
            'whatsapp_template_language' => Arr::get($config, 'review_template_language'),
        ];
    }

    public function updateSettings(array $payload): array
    {
        $settings = SiteSetting::singleton();
        $settings->google_review_url = Arr::get($payload, 'google_review_url');
        $settings->save();

        return $this->settings();
    }

    public function syncForPerformanceRecord(PerformanceRecord $record): GoogleReviewRequest
    {
        $record->loadMissing([
            'patient',
            'professional.publicProfile',
            'professional.specializations',
            'service.category',
        ]);

        $existing = GoogleReviewRequest::query()
            ->where('performance_record_id', $record->id)
            ->first();

        $snapshot = $this->buildSnapshot($record);
        $status = $this->initialStatus($record, $snapshot);

        if ($existing) {
            if ($existing->status === self::STATUS_SENT) {
                return $existing;
            }

            $preserveExcluded = $existing->status === self::STATUS_EXCLUDED;
            $preserveCancelled = $existing->status === self::STATUS_CANCELLED;
            $preserveManualSchedule = (bool) $existing->manual_override;
            $resolvedStatus = $preserveExcluded
                ? self::STATUS_EXCLUDED
                : ($preserveCancelled ? self::STATUS_CANCELLED : $status);
            $scheduledAt = $preserveManualSchedule || $preserveCancelled
                ? $existing->scheduled_at
                : $this->scheduleAtForRecord($record);

            $existing->fill(array_merge($snapshot, [
                'status' => $resolvedStatus,
                'scheduled_at' => $scheduledAt,
                'excluded_at' => $preserveExcluded ? $existing->excluded_at : null,
                'excluded_by' => $preserveExcluded ? $existing->excluded_by : null,
                'cancelled_at' => $preserveCancelled ? $existing->cancelled_at : null,
                'cancelled_by' => $preserveCancelled ? $existing->cancelled_by : null,
                'error_message' => $resolvedStatus === self::STATUS_ERROR ? $this->defaultEligibilityError($record, $snapshot) : ($preserveCancelled ? $existing->error_message : null),
                'template_payload' => $this->buildTemplatePayload($snapshot),
            ]));
            $existing->save();

            return $existing->refresh();
        }

        return GoogleReviewRequest::query()->create(array_merge($snapshot, [
            'patient_id' => $record->patient_id,
            'performance_record_id' => $record->id,
            'professional_id' => $record->professional_id,
            'specialization_id' => $snapshot['specialization_id'],
            'status' => $status,
            'scheduled_at' => $this->scheduleAtForRecord($record),
            'error_message' => $status === self::STATUS_ERROR ? $this->defaultEligibilityError($record, $snapshot) : null,
            'template_payload' => $this->buildTemplatePayload($snapshot),
        ]));
    }

    public function cancelForPerformanceRecord(PerformanceRecord $record, string $reason = 'Prestazione rimossa o non piu valida.'): void
    {
        GoogleReviewRequest::query()
            ->where('performance_record_id', $record->id)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_ERROR])
            ->update([
                'status' => self::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'error_message' => $reason,
                'updated_at' => now(),
            ]);
    }

    public function exclude(GoogleReviewRequest $request, ?User $actor = null): GoogleReviewRequest
    {
        $request->forceFill([
            'status' => self::STATUS_EXCLUDED,
            'excluded_at' => now(),
            'excluded_by' => $actor?->id,
            'error_message' => null,
        ])->save();

        return $request->refresh();
    }

    public function reschedule(GoogleReviewRequest $request, Carbon|string $scheduledAt, ?User $actor = null): GoogleReviewRequest
    {
        if ($request->status === self::STATUS_SENT) {
            throw ValidationException::withMessages([
                'request' => 'La richiesta e gia stata inviata.',
            ]);
        }

        $scheduledUtc = $this->normalizeScheduledAtInput($scheduledAt);
        $scheduledRome = $scheduledUtc->copy()->setTimezone(self::BUSINESS_TIMEZONE);

        Log::info('Google review rescheduled manually.', [
            'google_review_request_id' => $request->id,
            'performance_record_id' => $request->performance_record_id,
            'scheduled_at_utc' => $scheduledUtc->toIso8601String(),
            'scheduled_at_rome' => $scheduledRome->toIso8601String(),
            'actor_id' => $actor?->id,
        ]);

        $request->forceFill([
            'status' => self::STATUS_PENDING,
            'scheduled_at' => $scheduledUtc,
            'manual_override' => true,
            'manual_override_at' => now(),
            'manual_override_by' => $actor?->id,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'excluded_at' => null,
            'excluded_by' => null,
            'sent_at' => null,
            'error_message' => null,
            'provider_status' => null,
            'provider_message_id' => null,
            'provider_response' => null,
        ])->save();

        return $request->refresh();
    }

    public function cancel(GoogleReviewRequest $request, ?User $actor = null, ?string $reason = null): GoogleReviewRequest
    {
        $reasonText = trim((string) $reason);
        $resolvedReason = $reasonText !== '' ? $reasonText : 'Invio recensione annullato manualmente.';

        Log::info('Google review request cancelled manually.', [
            'google_review_request_id' => $request->id,
            'performance_record_id' => $request->performance_record_id,
            'actor_id' => $actor?->id,
            'reason' => $resolvedReason,
        ]);

        $request->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor?->id,
            'manual_override' => true,
            'manual_override_at' => now(),
            'manual_override_by' => $actor?->id,
            'error_message' => $resolvedReason,
        ])->save();

        return $request->refresh();
    }

    public function deleteCancelled(GoogleReviewRequest $request): void
    {
        if ($request->status !== self::STATUS_CANCELLED && $request->cancelled_at === null) {
            throw ValidationException::withMessages([
                'request' => 'Puoi eliminare solo richieste recensione annullate.',
            ]);
        }

        $request->delete();
    }

    public function retry(GoogleReviewRequest $request): GoogleReviewRequest
    {
        if ($request->status === self::STATUS_SENT) {
            throw ValidationException::withMessages([
                'request' => 'La richiesta e gia stata inviata.',
            ]);
        }

        $record = $request->performanceRecord()->with([
            'patient',
            'professional.publicProfile',
            'professional.specializations',
            'service.category',
        ])->firstOrFail();

        $snapshot = $this->buildSnapshot($record);
        $status = $this->initialStatus($record, $snapshot);

        $request->fill(array_merge($snapshot, [
            'status' => $status,
            'scheduled_at' => $this->nextManualRetrySlot(),
            'manual_override' => false,
            'manual_override_at' => null,
            'manual_override_by' => null,
            'sent_at' => null,
            'excluded_at' => null,
            'excluded_by' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'error_message' => $status === self::STATUS_ERROR ? $this->defaultEligibilityError($record, $snapshot) : null,
            'provider_status' => null,
            'provider_message_id' => null,
            'provider_response' => null,
            'template_payload' => $this->buildTemplatePayload($snapshot),
        ]));
        $request->save();

        return $request->refresh();
    }

    public function sendNow(GoogleReviewRequest $request): GoogleReviewRequest
    {
        return DB::transaction(function () use ($request): GoogleReviewRequest {
            $fresh = GoogleReviewRequest::query()
                ->with(['performanceRecord.patient', 'performanceRecord.professional.publicProfile', 'performanceRecord.professional.specializations', 'performanceRecord.service.category'])
                ->lockForUpdate()
                ->findOrFail($request->id);

            return $this->deliver($fresh);
        });
    }

    public function sendPending(): int
    {
        $nowRome = now(self::BUSINESS_TIMEZONE);
        if (! $this->isAllowedSendWindow($nowRome)) {
            return 0;
        }

        $sent = 0;

        GoogleReviewRequest::query()
            ->with(['performanceRecord.patient', 'performanceRecord.professional.publicProfile', 'performanceRecord.professional.specializations', 'performanceRecord.service.category'])
            ->where('status', self::STATUS_PENDING)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($requests) use (&$sent): void {
                foreach ($requests as $request) {
                    DB::transaction(function () use ($request, &$sent): void {
                        /** @var GoogleReviewRequest $fresh */
                        $fresh = GoogleReviewRequest::query()->lockForUpdate()->findOrFail($request->id);
                        $this->deliver($fresh);
                        if ($fresh->status === self::STATUS_SENT) {
                            $sent++;
                        }
                    });
                }
            });

        return $sent;
    }

    private function deliver(GoogleReviewRequest $request): GoogleReviewRequest
    {
        if (in_array($request->status, [self::STATUS_CANCELLED, self::STATUS_EXCLUDED], true)) {
            Log::info('Blocked Google review delivery for non-sendable request.', [
                'google_review_request_id' => $request->id,
                'status' => $request->status,
            ]);

            return $request->refresh();
        }

        $record = $request->performanceRecord;
        if (! $record) {
            $request->forceFill([
                'status' => self::STATUS_CANCELLED,
                'error_message' => 'Prestazione non piu disponibile.',
            ])->save();

            return $request->refresh();
        }

        $snapshot = $this->buildSnapshot($record);
        $request->fill($snapshot);
        $request->message_body = $snapshot['message_body'];
        $request->template_payload = $this->buildTemplatePayload($snapshot);

        if ($this->initialStatus($record, $snapshot) === self::STATUS_ERROR) {
            $request->forceFill([
                'status' => self::STATUS_ERROR,
                'error_message' => $this->defaultEligibilityError($record, $snapshot),
            ])->save();

            return $request->refresh();
        }

        $account = $this->whatsAppAccount();
        if (! $account || ! $account->enabled) {
            $request->forceFill([
                'status' => self::STATUS_ERROR,
                'error_message' => 'Integrazione WhatsApp non configurata o non attiva.',
            ])->save();

            return $request->refresh();
        }

        $status = $this->whatsAppPuppeteerService->status();
        if (($status['ready'] ?? false) !== true) {
            $request->forceFill([
                'status' => self::STATUS_ERROR,
                'error_message' => 'WhatsApp Business non collegato. Configuralo nella sezione Integrazioni.',
                'provider_status' => $status['state'] ?? 'not_ready',
                'provider_response' => $status,
            ])->save();

            return $request->refresh();
        }

        $result = $this->whatsAppPuppeteerService->send(
            (string) $request->patient_phone,
            (string) $request->message_body,
        );

        if ($result->deliveryStatus === 'sent') {
            $request->forceFill([
                'status' => self::STATUS_SENT,
                'sent_at' => now(),
                'error_message' => null,
                'provider_status' => $result->providerStatus,
                'provider_message_id' => $result->messageId,
                'provider_response' => $result->response,
            ])->save();
        } else {
            $request->forceFill([
                'status' => $result->deliveryStatus === 'excluded' ? self::STATUS_EXCLUDED : self::STATUS_ERROR,
                'error_message' => $result->errorMessage,
                'provider_status' => $result->providerStatus,
                'provider_message_id' => $result->messageId,
                'provider_response' => $result->response,
            ])->save();
        }

        return $request->refresh();
    }

    private function buildSnapshot(PerformanceRecord $record): array
    {
        $record->loadMissing([
            'patient',
            'professional.publicProfile',
            'professional.specializations',
            'service.specializations',
        ]);

        $patient = $record->patient;
        $professional = $record->professional;
        $specialization = $this->resolveReviewSpecialization($record);

        $reviewUrl = SiteSetting::singleton()->google_review_url;
        $patientName = trim((string) ($patient?->full_name ?: $patient?->first_name . ' ' . $patient?->last_name));
        $patientLastName = trim((string) ($patient?->last_name ?? ''));
        $patientSex = $this->normalizeSex($patient?->sex);
        $professionalSex = $this->normalizeProfessionalGender($professional?->gender?->value ?? $professional?->gender);
        $professionalTitle = $this->resolveDoctorHonorific($professionalSex);
        $professionalName = $this->formatProfessionalName($professional?->first_name, $professional?->last_name, $professional?->full_name, $record->professional_name_snapshot);
        $specializationName = trim((string) ($specialization?->name ?? ''));
        $normalizedPhone = $this->contactNormalizer->normalizePhone($patient?->phone);
        $professionalRole = $this->resolveProfessionalRoleTitle($specialization, $professionalSex);

        $messageBody = $this->buildMessage(
            patientGreeting: $this->buildPatientGreeting($patientSex, $patientLastName),
            professionalSex: $professionalSex,
            professionalTitle: $professionalTitle,
            professionalName: $professionalName !== '' ? $professionalName : null,
            professionalRole: $professionalRole,
            reviewUrl: $reviewUrl,
        );

        return [
            'specialization_id' => $specialization?->id,
            'patient_name' => $patientName !== '' ? $patientName : 'Paziente',
            'patient_phone' => $normalizedPhone,
            'professional_name' => $professionalName !== '' ? $professionalName : null,
            'professional_title' => $professionalTitle !== '' ? $professionalTitle : null,
            'specialization_name' => $specializationName !== '' ? $specializationName : null,
            'review_url' => $reviewUrl,
            'message_body' => $messageBody,
        ];
    }

    private function buildMessage(
        string $patientGreeting,
        ?string $professionalSex,
        ?string $professionalTitle,
        ?string $professionalName,
        ?string $professionalRole,
        ?string $reviewUrl,
    ): string {
        if (! $professionalRole || ! $professionalTitle || ! $professionalName || ! in_array($professionalSex, ['male', 'female'], true)) {
            return trim(implode("\n", [
                $patientGreeting,
                'grazie per aver scelto Remedic.',
                '',
                'Speriamo che la sua esperienza presso Remedic sia stata positiva.',
                '',
                'Se le fa piacere, puo lasciare una recensione su Google per aiutarci a migliorare e far conoscere il nostro centro medico:',
                '',
                $reviewUrl ?: '[link recensione Google non configurato]',
                '',
                'Grazie da tutto il team Remedic.',
            ]));
        }

        $roleReference = sprintf('%s %s', $professionalSex === 'female' ? 'la nostra' : 'il nostro', $professionalRole);
        $doctorReference = sprintf('%s %s', $professionalSex === 'female' ? 'la' : 'il', trim($professionalTitle . ' ' . $professionalName));
        $professionalLabel = sprintf('%s, %s,', $roleReference, $doctorReference);

        return trim(implode("\n", [
            $patientGreeting,
            'grazie per aver scelto Remedic.',
            '',
            "Ci auguriamo che la sua esperienza con {$professionalLabel} sia stata positiva.",
            '',
            'Se le fa piacere, può lasciare una recensione su Google: per noi è molto importante e aiuta altre persone a conoscere il nostro centro medico.',
            '',
            $reviewUrl ?: '[link recensione Google non configurato]',
            '',
            'Grazie da tutto il team Remedic.',
        ]));
    }

    private function buildPatientGreeting(?string $patientSex, string $patientLastName): string
    {
        if ($patientLastName === '') {
            return 'Gentile paziente,';
        }

        return match ($patientSex) {
            'male' => "Gentile sig. {$patientLastName},",
            'female' => "Gentile sig.ra {$patientLastName},",
            default => 'Gentile paziente,',
        };
    }

    private function normalizeSex(mixed $value): ?string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'male', 'm', 'maschio', 'uomo' => 'male',
            'female', 'f', 'femmina', 'donna' => 'female',
            default => null,
        };
    }

    private function normalizeProfessionalGender(mixed $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'male' => 'male',
            'female' => 'female',
            default => null,
        };
    }

    private function formatProfessionalName(
        ?string $firstName,
        ?string $lastName,
        ?string $fullName,
        ?string $snapshotName,
    ): string {
        $resolvedFirstName = trim((string) $firstName);
        $resolvedLastName = trim((string) $lastName);

        if ($resolvedFirstName !== '' || $resolvedLastName !== '') {
            return trim($resolvedFirstName . ' ' . $resolvedLastName);
        }

        $resolvedFullName = trim((string) $fullName);
        if ($resolvedFullName !== '') {
            return $resolvedFullName;
        }

        return trim((string) $snapshotName);
    }

    private function resolveReviewSpecialization(PerformanceRecord $record): ?Specialization
    {
        $service = $record->service;
        if (! $service || ! $service->relationLoaded('specializations')) {
            return null;
        }

        return $service->specializations
            ->sortBy(fn(Specialization $specialization): string => sprintf(
                '%d-%05d-%010d',
                $specialization->pivot?->is_primary ? 0 : 1,
                (int) ($specialization->pivot?->sort_order ?? 99999),
                $specialization->id,
            ))
            ->first();
    }

    private function resolveDoctorHonorific(?string $professionalSex): ?string
    {
        return match ($professionalSex) {
            'male' => 'Dott.',
            'female' => 'Dott.ssa',
            default => null,
        };
    }

    private function resolveProfessionalRoleTitle(?Specialization $specialization, ?string $professionalSex): ?string
    {
        if (! $specialization || ! in_array($professionalSex, ['male', 'female'], true)) {
            return null;
        }

        $label = $professionalSex === 'female'
            ? $specialization->professional_title_female
            : $specialization->professional_title_male;

        $normalized = trim((string) $label);

        return $normalized !== '' ? $normalized : null;
    }

    private function initialStatus(PerformanceRecord $record, array $snapshot): string
    {
        $patient = $record->patient;

        if (! $patient) {
            return self::STATUS_ERROR;
        }

        if ($patient->excluded_from_campaigns) {
            return self::STATUS_EXCLUDED;
        }

        if (! $patient->contactable_whatsapp) {
            return self::STATUS_ERROR;
        }

        if (! filled($snapshot['patient_phone'])) {
            return self::STATUS_ERROR;
        }

        return self::STATUS_PENDING;
    }

    private function defaultEligibilityError(PerformanceRecord $record, array $snapshot): ?string
    {
        $patient = $record->patient;
        if (! $patient) {
            return 'Paziente non disponibile.';
        }

        if ($patient->excluded_from_campaigns) {
            return 'Paziente escluso dai contatti automatici.';
        }

        if (! $patient->contactable_whatsapp) {
            return 'Paziente non contattabile via WhatsApp.';
        }

        if (! filled($snapshot['patient_phone'])) {
            return 'Numero WhatsApp mancante o non valido.';
        }

        return null;
    }

    private function buildTemplatePayload(array $snapshot): array
    {
        $account = $this->whatsAppAccount();
        $config = $account?->config_json ?? [];

        return [
            'template_name' => Arr::get($config, 'review_template_name'),
            'template_language' => Arr::get($config, 'review_template_language'),
            'variables' => [
                'patient_name' => $snapshot['patient_name'],
                'professional_name' => $snapshot['professional_name'],
                'professional_title' => $snapshot['professional_title'],
                'specialization_name' => $snapshot['specialization_name'],
                'review_url' => $snapshot['review_url'],
            ],
        ];
    }

    private function scheduleAtForRecord(PerformanceRecord $record): Carbon
    {
        $createdAtLocal = ($record->created_at
            ? CarbonImmutable::instance($record->created_at)
            : CarbonImmutable::now(config('app.timezone', 'UTC')))
            ->setTimezone(self::BUSINESS_TIMEZONE);
        $performedDayLocal = CarbonImmutable::parse(
            (string) ($record->performed_at ?: $createdAtLocal->toDateString()),
            self::BUSINESS_TIMEZONE,
        )->startOfDay();
        $visitShift = $record->visit_shift instanceof VisitShift
            ? $record->visit_shift
            : VisitShift::tryFrom((string) $record->visit_shift) ?? VisitShift::Morning;

        $candidateLocal = $visitShift === VisitShift::Afternoon
            ? $performedDayLocal->addDay()->setTime(self::AUTO_SEND_START_HOUR, self::AUTO_SEND_START_MINUTE)
            : $performedDayLocal->setTime(16, 30);

        $reason = $visitShift === VisitShift::Afternoon
            ? 'afternoon_next_day_morning_slot'
            : 'morning_same_day_slot';

        if ($candidateLocal->lessThanOrEqualTo($createdAtLocal)) {
            $candidateLocal = $this->nextMorningSlotAfter($createdAtLocal);
            $reason = 'theoretical_slot_already_passed';
        }

        if (! $this->isWithinAllowedWindow($candidateLocal)) {
            $candidateLocal = $this->nextMorningSlotAfter($candidateLocal);
            $reason = 'outside_allowed_send_window';
        }

        Log::info('Google review auto schedule calculated.', [
            'performance_record_id' => $record->id,
            'visit_shift' => $visitShift->value,
            'performed_at' => $performedDayLocal->toDateString(),
            'created_at_rome' => $createdAtLocal->toIso8601String(),
            'scheduled_at_rome' => $candidateLocal->toIso8601String(),
            'scheduled_at_utc' => $candidateLocal->setTimezone(config('app.timezone', 'UTC'))->toIso8601String(),
            'reason' => $reason,
        ]);

        return $candidateLocal->setTimezone(config('app.timezone', 'UTC'))->toMutable();
    }

    private function nextManualRetrySlot(): Carbon
    {
        $candidate = CarbonImmutable::now(self::BUSINESS_TIMEZONE);

        if ($candidate->lessThan($candidate->setTime(self::AUTO_SEND_START_HOUR, self::AUTO_SEND_START_MINUTE, 0))) {
            $candidate = $candidate->setTime(self::AUTO_SEND_START_HOUR, self::AUTO_SEND_START_MINUTE, 0);
        } elseif (! $this->isAllowedSendWindow($candidate)) {
            $candidate = $this->nextMorningSlotAfter($candidate);
        }

        return $candidate->setTimezone(config('app.timezone', 'UTC'))->toMutable();
    }

    private function isAllowedSendWindow(\Carbon\CarbonInterface $nowRome): bool
    {
        $minutes = ($nowRome->hour * 60) + $nowRome->minute;

        return $minutes >= (self::AUTO_SEND_START_HOUR * 60) + self::AUTO_SEND_START_MINUTE
            && $minutes <= (self::AUTO_SEND_END_HOUR * 60) + self::AUTO_SEND_END_MINUTE;
    }

    private function isWithinAllowedWindow(CarbonImmutable $candidate): bool
    {
        return $this->isAllowedSendWindow($candidate);
    }

    private function nextMorningSlotAfter(CarbonImmutable $reference): CarbonImmutable
    {
        return $reference
            ->addDay()
            ->setTime(self::AUTO_SEND_START_HOUR, self::AUTO_SEND_START_MINUTE, 0);
    }

    private function normalizeScheduledAtInput(Carbon|string $scheduledAt): Carbon
    {
        if ($scheduledAt instanceof Carbon) {
            return $scheduledAt->copy()->setTimezone(config('app.timezone', 'UTC'));
        }

        return Carbon::parse($scheduledAt, self::BUSINESS_TIMEZONE)
            ->setTimezone(config('app.timezone', 'UTC'));
    }

    private function whatsAppAccount(): ?ExternalProviderAccount
    {
        return ExternalProviderAccount::query()
            ->where('provider', self::WHATSAPP_PROVIDER)
            ->first();
    }
}
