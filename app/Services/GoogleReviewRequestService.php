<?php

namespace App\Services;

use App\Models\ExternalProviderAccount;
use App\Models\GoogleReviewRequest;
use App\Models\PerformanceRecord;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Marketing\MarketingContactNormalizer;
use App\Services\Marketing\WhatsAppPuppeteerService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
    private const DEFAULT_DELAY_DAYS = 3;
    private const DEFAULT_DELAY_HOURS = 0;
    private const DEFAULT_DELAY_MINUTES = 0;
    private const DEFAULT_DELAY_SECONDS = 0;

    public function __construct(
        private readonly MarketingContactNormalizer $contactNormalizer,
        private readonly WhatsAppPuppeteerService $whatsAppPuppeteerService,
    ) {
    }

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
            'google_review_delay_days' => (int) ($settings->google_review_delay_days ?? self::DEFAULT_DELAY_DAYS),
            'google_review_delay_hours' => (int) ($settings->google_review_delay_hours ?? self::DEFAULT_DELAY_HOURS),
            'google_review_delay_minutes' => (int) ($settings->google_review_delay_minutes ?? self::DEFAULT_DELAY_MINUTES),
            'google_review_delay_seconds' => (int) ($settings->google_review_delay_seconds ?? self::DEFAULT_DELAY_SECONDS),
            'whatsapp_template_name' => Arr::get($config, 'review_template_name'),
            'whatsapp_template_language' => Arr::get($config, 'review_template_language'),
        ];
    }

    public function updateSettings(array $payload): array
    {
        $settings = SiteSetting::singleton();
        $settings->google_review_url = Arr::get($payload, 'google_review_url');
        $settings->google_review_delay_days = max(0, (int) Arr::get($payload, 'google_review_delay_days', self::DEFAULT_DELAY_DAYS));
        $settings->google_review_delay_hours = max(0, min(23, (int) Arr::get($payload, 'google_review_delay_hours', self::DEFAULT_DELAY_HOURS)));
        $settings->google_review_delay_minutes = max(0, min(59, (int) Arr::get($payload, 'google_review_delay_minutes', self::DEFAULT_DELAY_MINUTES)));
        $settings->google_review_delay_seconds = max(0, min(59, (int) Arr::get($payload, 'google_review_delay_seconds', self::DEFAULT_DELAY_SECONDS)));
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

            $existing->fill(array_merge($snapshot, [
                'status' => $existing->status === self::STATUS_EXCLUDED ? self::STATUS_EXCLUDED : $status,
                'scheduled_at' => $this->scheduleAtForRecord($record),
                'excluded_at' => $existing->status === self::STATUS_EXCLUDED ? $existing->excluded_at : null,
                'excluded_by' => $existing->status === self::STATUS_EXCLUDED ? $existing->excluded_by : null,
                'error_message' => $status === self::STATUS_ERROR ? $this->defaultEligibilityError($record, $snapshot) : null,
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
            'sent_at' => null,
            'excluded_at' => null,
            'excluded_by' => null,
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
        $nowRome = now('Europe/Rome');
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
        $patient = $record->patient;
        $professional = $record->professional;
        $specialization = $professional?->specializations
            ?->firstWhere('name', $record->category_name_snapshot)
            ?? $professional?->specializations?->firstWhere('pivot.is_primary', true)
            ?? $professional?->specializations?->first();

        $reviewUrl = SiteSetting::singleton()->google_review_url;
        $patientName = trim((string) ($patient?->full_name ?: $patient?->first_name.' '.$patient?->last_name));
        $patientLastName = trim((string) ($patient?->last_name ?? ''));
        $patientSex = $this->normalizeSex($patient?->sex);
        $professionalTitle = trim((string) ($professional?->publicProfile?->title_prefix ?? ''));
        $professionalSex = $this->inferProfessionalSex($professionalTitle);
        $professionalName = $this->formatProfessionalName($professional?->first_name, $professional?->last_name, $professional?->full_name, $record->professional_name_snapshot);
        $specializationName = trim((string) ($specialization?->name ?? $record->category_name_snapshot));
        $normalizedPhone = $this->contactNormalizer->normalizePhone($patient?->phone);

        $messageBody = $this->buildMessage(
            patientGreeting: $this->buildPatientGreeting($patientSex, $patientLastName),
            professionalSex: $professionalSex,
            professionalName: $professionalName !== '' ? $professionalName : null,
            specializationName: $specializationName !== '' ? $specializationName : null,
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
        ?string $professionalName,
        ?string $specializationName,
        ?string $reviewUrl,
    ): string {
        $roleLabel = $this->resolveProfessionalRole($specializationName, $professionalSex);
        $roleReference = $roleLabel
            ? sprintf('%s %s', $professionalSex === 'female' ? 'la nostra' : 'il nostro', $roleLabel)
            : ($professionalSex === 'female' ? 'la nostra professionista' : 'il nostro professionista');
        $doctorReference = $professionalName
            ? sprintf('%s %s', $professionalSex === 'female' ? 'la dott.ssa' : 'il dott.', $professionalName)
            : ($professionalSex === 'female' ? 'la dott.ssa del centro' : 'il dott. del centro');
        $professionalLabel = sprintf('%s, %s', $roleReference, $doctorReference);

        return trim(implode("\n", [
            $patientGreeting,
            'grazie per aver scelto Remedic.',
            '',
            "Speriamo che la sua esperienza con {$professionalLabel} sia stata positiva.",
            '',
            'Se le fa piacere, puo lasciare una recensione su Google per aiutarci a migliorare e far conoscere il nostro centro medico:',
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

    private function inferProfessionalSex(?string $professionalTitle): ?string
    {
        $normalizedTitle = Str::lower(trim((string) $professionalTitle));

        if ($normalizedTitle === '') {
            return null;
        }

        if (Str::contains($normalizedTitle, ['dott.ssa', 'dottoressa', 'ssa'])) {
            return 'female';
        }

        if (Str::contains($normalizedTitle, ['dott', 'dr', 'doctor'])) {
            return 'male';
        }

        return null;
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
            return trim($resolvedFirstName.' '.$resolvedLastName);
        }

        $resolvedFullName = trim((string) $fullName);
        if ($resolvedFullName !== '') {
            return $resolvedFullName;
        }

        return trim((string) $snapshotName);
    }

    private function resolveProfessionalRole(?string $specializationName, ?string $professionalSex): ?string
    {
        $normalizedSpecialization = $this->normalizeSpecializationKey($specializationName);
        $isFemale = $professionalSex === 'female';

        $roles = [
            'cardiologia' => ['male' => 'cardiologo', 'female' => 'cardiologa'],
            'neurologia' => ['male' => 'neurologo', 'female' => 'neurologa'],
            'ginecologia' => ['male' => 'ginecologo', 'female' => 'ginecologa'],
            'dermatologia' => ['male' => 'dermatologo', 'female' => 'dermatologa'],
            'urologia' => ['male' => 'urologo', 'female' => 'urologa'],
            'endocrinologia' => ['male' => 'endocrinologo', 'female' => 'endocrinologa'],
            'reumatologia' => ['male' => 'reumatologo', 'female' => 'reumatologa'],
            'medicina interna' => ['male' => 'internista', 'female' => 'internista'],
            'chirurgia vascolare' => ['male' => 'chirurgo vascolare', 'female' => 'chirurga vascolare'],
            'dietologia' => ['male' => 'dietologo', 'female' => 'dietologa'],
            'medicina estetica' => ['male' => 'medico estetico', 'female' => 'medica estetica'],
            'chirurgia plastica' => ['male' => 'chirurgo plastico', 'female' => 'chirurga plastica'],
            'psicologia clinica' => ['male' => 'psicologo clinico', 'female' => 'psicologa clinica'],
        ];

        if (isset($roles[$normalizedSpecialization])) {
            return $roles[$normalizedSpecialization][$isFemale ? 'female' : 'male'];
        }

        foreach ($roles as $key => $labels) {
            if (Str::startsWith($normalizedSpecialization, $key)) {
                return $labels[$isFemale ? 'female' : 'male'];
            }
        }

        return null;
    }

    private function normalizeSpecializationKey(?string $specializationName): string
    {
        $normalized = Str::of((string) $specializationName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->trim()
            ->value();

        return $normalized;
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
        $settings = SiteSetting::singleton();
        $baseTime = $record->created_at
            ? CarbonImmutable::instance($record->created_at)
            : CarbonImmutable::parse($record->performed_at ?: now(), config('app.timezone', 'UTC'));

        $reference = $baseTime
            ->addDays((int) ($settings->google_review_delay_days ?? self::DEFAULT_DELAY_DAYS))
            ->addHours((int) ($settings->google_review_delay_hours ?? self::DEFAULT_DELAY_HOURS))
            ->addMinutes((int) ($settings->google_review_delay_minutes ?? self::DEFAULT_DELAY_MINUTES))
            ->addSeconds((int) ($settings->google_review_delay_seconds ?? self::DEFAULT_DELAY_SECONDS));

        return $reference->setTimezone(config('app.timezone', 'UTC'))->toMutable();
    }

    private function nextManualRetrySlot(): Carbon
    {
        $candidate = CarbonImmutable::now('Europe/Rome');

        if ($candidate->hour < 9 || ($candidate->hour === 9 && $candidate->minute < 30)) {
            $candidate = $candidate->setTime(10, 30, 0);
        } elseif (! $this->isAllowedSendWindow($candidate)) {
            $candidate = $candidate->addDay()->setTime(10, 30, 0);
        }

        if ($candidate->isSunday()) {
            $candidate = $candidate->addDay()->setTime(10, 30, 0);
        }

        return $candidate->setTimezone(config('app.timezone', 'UTC'))->toMutable();
    }

    private function isAllowedSendWindow(\Carbon\CarbonInterface $nowRome): bool
    {
        $minutes = ($nowRome->hour * 60) + $nowRome->minute;

        return $minutes >= (9 * 60) + 30 && $minutes <= (18 * 60) + 30;
    }

    private function whatsAppAccount(): ?ExternalProviderAccount
    {
        return ExternalProviderAccount::query()
            ->where('provider', self::WHATSAPP_PROVIDER)
            ->first();
    }
}
