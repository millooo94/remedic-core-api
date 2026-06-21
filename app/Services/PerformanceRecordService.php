<?php

namespace App\Services;

use App\DTOs\PerformanceCalculationInput;
use App\Enums\CalculationMode;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PerformanceSplitMode;
use App\Enums\PerformanceSplitSubjectType;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PerformanceRecord;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Support\Numbers\ScaledNumber;
use App\Models\User;
use App\Support\Filters\PerformanceRecordFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PerformanceRecordService
{
    public function __construct(
        private readonly PerformanceCalculationService $calculationService,
        private readonly PerformanceRecordFilters $filters,
        private readonly PerformanceExpenseSyncService $performanceExpenseSyncService,
        private readonly GoogleReviewRequestService $googleReviewRequestService,
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        $query = PerformanceRecord::query()->with(['patient', 'patients', 'professional', 'service.category', 'splits.professional']);

        $this->filters->apply($query, $filters);

        return $this->filters->applySort($query, $filters['sort'] ?? null);
    }

    public function create(array $payload, User $actor): PerformanceRecord
    {
        return DB::transaction(function () use ($payload, $actor): PerformanceRecord {
            $state = $this->prepareState($payload, $actor);
            $record = PerformanceRecord::query()->create($state['attributes']);
            $this->syncPatients($record, $state['patient_ids']);
            $this->syncSplits($record, $state['splits']);
            $record->refresh();
            $this->performanceExpenseSyncService->syncFromPerformanceRecord($record);
            $this->googleReviewRequestService->syncForPerformanceRecord(
                $record->load(['patient', 'professional.publicProfile', 'professional.specializations', 'service.category'])
            );
            $this->audit($actor, 'performance_record', $record->id, 'created', null, $this->snapshotForAudit($record));

            return $record->load(['patient', 'patients', 'professional', 'service.category', 'splits.professional']);
        });
    }

    public function update(PerformanceRecord $performanceRecord, array $payload, User $actor): PerformanceRecord
    {
        return DB::transaction(function () use ($performanceRecord, $payload, $actor): PerformanceRecord {
            $before = $this->snapshotForAudit($performanceRecord);
            $state = $this->prepareState($payload, $actor, $performanceRecord);
            $performanceRecord->fill($state['attributes']);
            $performanceRecord->save();
            $this->syncPatients($performanceRecord, $state['patient_ids']);
            $this->syncSplits($performanceRecord, $state['splits']);
            $performanceRecord->refresh();
            $this->performanceExpenseSyncService->syncFromPerformanceRecord($performanceRecord);
            $this->googleReviewRequestService->syncForPerformanceRecord(
                $performanceRecord->load(['patient', 'professional.publicProfile', 'professional.specializations', 'service.category'])
            );
            $this->audit($actor, 'performance_record', $performanceRecord->id, 'updated', $before, $this->snapshotForAudit($performanceRecord));

            return $performanceRecord->load(['patient', 'patients', 'professional', 'service.category', 'splits.professional']);
        });
    }

    public function delete(PerformanceRecord $performanceRecord, User $actor): void
    {
        DB::transaction(function () use ($performanceRecord, $actor): void {
            $before = $this->snapshotForAudit($performanceRecord);
            $this->performanceExpenseSyncService->deleteForPerformanceRecord($performanceRecord);
            $this->googleReviewRequestService->cancelForPerformanceRecord($performanceRecord);
            $performanceRecord->delete();
            $this->audit($actor, 'performance_record', $performanceRecord->id, 'deleted', $before, null);
        });
    }

    private function prepareState(array $payload, User $actor, ?PerformanceRecord $existing = null): array
    {
        $patientIds = $this->resolvePatientIds($payload);
        $professional = Professional::query()->findOrFail($payload['professional_id']);
        $service = isset($payload['service_id']) ? Service::query()->with('category')->findOrFail($payload['service_id']) : null;
        $patientsById = Patient::query()
            ->whereIn('id', $patientIds)
            ->get()
            ->keyBy('id');

        if (count($patientIds) !== $patientsById->count()) {
            throw ValidationException::withMessages([
                'patient_ids' => 'Uno o piu pazienti selezionati non sono validi.',
            ]);
        }

        if ($service !== null) {
            $linked = ProfessionalService::query()
                ->where('professional_id', $professional->id)
                ->where('service_id', $service->id)
                ->exists();

            if (! $linked) {
                throw ValidationException::withMessages([
                    'service_id' => 'La prestazione selezionata non e associata al professionista.',
                ]);
            }
        }

        $performedAt = Carbon::parse($payload['performed_at']);
        $quantity = max(1, (int) ($payload['quantity'] ?? 0));

        if (count($patientIds) !== $quantity) {
            throw ValidationException::withMessages([
                'patient_ids' => sprintf('Il numero di pazienti selezionati deve essere uguale alla quantita (%d).', $quantity),
            ]);
        }

        $splitMode = PerformanceSplitMode::from((string) ($payload['split_mode'] ?? ($existing?->split_mode?->value ?? 'standard')));
        $calculationModeValue = (string) ($payload['calculation_mode'] ?? ($existing?->calculation_mode?->value ?? CalculationMode::Percentage->value));
        $calculationMode = CalculationMode::from($calculationModeValue);

        $baseAmounts = $this->calculateBaseAmounts(
            quantityRaw: $payload['quantity'],
            unitAmountRaw: $payload['unit_amount'],
            directCostRaw: $payload['direct_cost'] ?? ($existing?->direct_cost ?? '0'),
        );

        $splits = [];
        $percentageValue = null;
        $fixedAmount = null;

        if ($splitMode === PerformanceSplitMode::Standard) {
            $calculation = $this->calculationService->calculate(
                new PerformanceCalculationInput(
                    calculationMode: $calculationMode,
                    quantity: (string) $payload['quantity'],
                    unitAmount: (string) $payload['unit_amount'],
                    directCost: isset($payload['direct_cost']) ? (string) $payload['direct_cost'] : (string) ($existing?->direct_cost ?? '0'),
                    percentageValue: isset($payload['percentage_value']) ? (string) $payload['percentage_value'] : null,
                    fixedAmount: isset($payload['fixed_amount']) ? (string) $payload['fixed_amount'] : null,
                ),
            );

            $professionalAmount = $calculation->professionalAmount;
            $centerAmount = $calculation->centerAmount;
            $percentageValue = $calculation->percentageValue;
            $fixedAmount = $calculation->fixedAmount;
        } else {
            $advanced = $this->normalizeAdvancedSplits(
                payloadSplits: $payload['advanced_splits'] ?? [],
                netDivisibleAmount: $baseAmounts['net_divisible_amount'],
            );
            $splits = $advanced['splits'];
            $professionalAmount = $advanced['professional_amount'];
            $centerAmount = $advanced['center_amount'];
        }

        $serviceName = $service?->display_name ?? ($payload['service_name'] ?? null);

        if ($serviceName === null || trim($serviceName) === '') {
            throw ValidationException::withMessages([
                'service_name' => 'Il nome della prestazione e obbligatorio quando non viene selezionata dal catalogo.',
            ]);
        }

        $manualArea = isset($payload['area_name']) ? trim((string) $payload['area_name']) : '';
        $isInvoiced = (bool) ($payload['is_invoiced'] ?? $existing?->is_invoiced ?? false);
        $isBlack = (bool) ($payload['is_black'] ?? $existing?->is_black ?? false);
        $isPromo = (bool) ($payload['is_promo'] ?? $existing?->is_promo ?? false);
        $paymentMethod = PaymentMethod::tryFrom((string) ($payload['payment_method'] ?? ''))
            ?? ($existing?->payment_method instanceof PaymentMethod ? $existing->payment_method : null)
            ?? PaymentMethod::Card;

        if ($isBlack && $isInvoiced) {
            throw ValidationException::withMessages([
                'is_black' => 'Una prestazione black non puo essere segnata come fatturata.',
                'is_invoiced' => 'Una prestazione black non puo essere segnata come fatturata.',
            ]);
        }

        if ($isBlack && $paymentMethod === PaymentMethod::Card) {
            throw ValidationException::withMessages([
                'payment_method' => 'Una prestazione black non puo essere registrata con pagamento carta.',
            ]);
        }

        if ($isBlack && $isPromo) {
            throw ValidationException::withMessages([
                'is_black' => 'Una prestazione non puo essere contemporaneamente black e promo.',
                'is_promo' => 'Una prestazione non puo essere contemporaneamente black e promo.',
            ]);
        }

        return [
            'attributes' => [
                'performed_at' => $performedAt->toDateString(),
                'patient_id' => $patientIds[0] ?? null,
                'professional_id' => $professional->id,
                'professional_name_snapshot' => $professional->full_name,
                'category_name_snapshot' => $service?->category?->name ?: ($manualArea !== '' ? $manualArea : $professional->area_name),
                'service_id' => $service?->id,
                'service_name_snapshot' => $serviceName,
                'quantity' => $baseAmounts['quantity'],
                'unit_amount' => $baseAmounts['unit_amount'],
                'total_amount' => $baseAmounts['total_amount'],
                'direct_cost' => $baseAmounts['direct_cost'],
                'calculation_mode' => $calculationMode->value,
                'split_mode' => $splitMode->value,
                'percentage_value' => $percentageValue,
                'fixed_amount' => $fixedAmount,
                'professional_amount' => $professionalAmount,
                'center_amount' => $centerAmount,
                'payment_method' => $paymentMethod->value,
                'payment_status' => PaymentStatus::tryFrom((string) ($payload['payment_status'] ?? ''))?->value
                    ?? $existing?->payment_status?->value
                    ?? PaymentStatus::DaPagare->value,
                'is_invoiced' => $isInvoiced,
                'is_black' => $isBlack,
                'is_promo' => $isPromo,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $existing?->created_by ?? $actor->id,
                'updated_by' => $actor->id,
            ],
            'patient_ids' => $patientIds,
            'splits' => $splitMode === PerformanceSplitMode::Advanced ? $splits : [],
        ];
    }

    private function calculateBaseAmounts(mixed $quantityRaw, mixed $unitAmountRaw, mixed $directCostRaw): array
    {
        $quantity = max(1, (int) $quantityRaw);
        $unitAmountCents = ScaledNumber::assertWholeAmount($unitAmountRaw, 'unit_amount');
        $totalCents = $quantity * $unitAmountCents;
        $directCostCents = ScaledNumber::assertWholeAmount($directCostRaw ?? '0', 'direct_cost');

        if ($directCostCents < 0) {
            throw ValidationException::withMessages([
                'direct_cost' => 'Il costo diretto prestazione deve essere maggiore o uguale a 0.',
            ]);
        }

        if ($directCostCents > $totalCents) {
            throw ValidationException::withMessages([
                'direct_cost' => 'Il costo diretto prestazione non puo superare l\'importo prestazione.',
            ]);
        }

        return [
            'quantity' => (string) $quantity,
            'unit_amount' => ScaledNumber::fromScaledInteger($unitAmountCents, 2),
            'total_amount' => ScaledNumber::fromScaledInteger($totalCents, 2),
            'direct_cost' => ScaledNumber::fromScaledInteger($directCostCents, 2),
            'net_divisible_amount' => ScaledNumber::fromScaledInteger($totalCents - $directCostCents, 2),
        ];
    }

    private function normalizeAdvancedSplits(array $payloadSplits, string $netDivisibleAmount): array
    {
        if ($payloadSplits === []) {
            throw ValidationException::withMessages([
                'advanced_splits' => 'In modalita avanzata devi inserire almeno una quota.',
            ]);
        }

        $professionalIds = collect($payloadSplits)
            ->filter(fn (array $split) => ($split['subject_type'] ?? null) === PerformanceSplitSubjectType::Professional->value)
            ->map(fn (array $split) => (int) ($split['professional_id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $professionalsById = Professional::query()
            ->whereIn('id', $professionalIds)
            ->get()
            ->keyBy('id');

        $normalized = [];
        $professionalTotalCents = 0;
        $centerTotalCents = 0;

        foreach (array_values($payloadSplits) as $index => $split) {
            $subjectType = PerformanceSplitSubjectType::from((string) ($split['subject_type'] ?? 'professional'));
            $amountCents = ScaledNumber::assertWholeAmount(
                value: $split['amount'] ?? '0',
                field: "advanced_splits.$index.amount",
                message: 'Inserisci un importo intero senza centesimi.',
            );

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    "advanced_splits.$index.amount" => 'L\'importo quota deve essere maggiore di zero.',
                ]);
            }

            $professionalId = null;
            $professionalSnapshot = null;
            if ($subjectType === PerformanceSplitSubjectType::Professional) {
                $professionalId = (int) ($split['professional_id'] ?? 0);
                if ($professionalId <= 0) {
                    throw ValidationException::withMessages([
                        "advanced_splits.$index.professional_id" => 'Se il tipo soggetto e Professionista, devi selezionare il professionista.',
                    ]);
                }

                /** @var Professional|null $rowProfessional */
                $rowProfessional = $professionalsById->get($professionalId);
                if (! $rowProfessional) {
                    throw ValidationException::withMessages([
                        "advanced_splits.$index.professional_id" => 'Professionista non valido nella ripartizione avanzata.',
                    ]);
                }

                $professionalSnapshot = $rowProfessional->full_name;
                $professionalTotalCents += $amountCents;
            } else {
                $centerTotalCents += $amountCents;
            }

            $normalized[] = [
                'subject_type' => $subjectType->value,
                'professional_id' => $professionalId,
                'professional_name_snapshot' => $professionalSnapshot,
                'amount' => ScaledNumber::fromScaledInteger($amountCents, 2),
                'description' => isset($split['description']) && trim((string) $split['description']) !== ''
                    ? trim((string) $split['description'])
                    : null,
                'sort_order' => $index,
            ];
        }

        $sumCents = $professionalTotalCents + $centerTotalCents;
        $netCents = ScaledNumber::toScaledInteger($netDivisibleAmount, 2, 'net_divisible_amount');
        if (abs($sumCents - $netCents) > 0) {
            throw ValidationException::withMessages([
                'advanced_splits' => sprintf(
                    'La somma quote (%s) deve essere uguale alla base netta da ripartire (%s).',
                    ScaledNumber::fromScaledInteger($sumCents, 2),
                    ScaledNumber::fromScaledInteger($netCents, 2),
                ),
            ]);
        }

        return [
            'splits' => $normalized,
            'professional_amount' => ScaledNumber::fromScaledInteger($professionalTotalCents, 2),
            'center_amount' => ScaledNumber::fromScaledInteger($centerTotalCents, 2),
        ];
    }

    private function syncSplits(PerformanceRecord $record, array $splits): void
    {
        $record->splits()->delete();

        if ($splits !== []) {
            $record->splits()->createMany($splits);
        }
    }

    private function syncPatients(PerformanceRecord $record, array $patientIds): void
    {
        $syncPayload = [];

        foreach (array_values($patientIds) as $index => $patientId) {
            $syncPayload[(int) $patientId] = ['sort_order' => $index];
        }

        $record->patients()->sync($syncPayload);
    }

    private function resolvePatientIds(array $payload): array
    {
        $rawPatientIds = $payload['patient_ids'] ?? [];
        if (! is_array($rawPatientIds) || $rawPatientIds === []) {
            $rawPatientIds = isset($payload['patient_id']) && $payload['patient_id'] ? [$payload['patient_id']] : [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            array_filter($rawPatientIds, static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0),
        )));
    }

    private function snapshotForAudit(PerformanceRecord $record): array
    {
        return $record->loadMissing(['patient', 'patients', 'splits.professional'])->toArray();
    }

    private function audit(User $actor, string $entityType, ?int $entityId, string $action, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'created_at' => now(),
        ]);
    }
}
