<?php

namespace App\Services;

use App\DTOs\PerformanceCalculationInput;
use App\Enums\CalculationMode;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\PerformanceRecord;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
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
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        $query = PerformanceRecord::query()->with(['professional', 'service.category']);

        $this->filters->apply($query, $filters);

        return $this->filters->applySort($query, $filters['sort'] ?? null);
    }

    public function create(array $payload, User $actor): PerformanceRecord
    {
        return DB::transaction(function () use ($payload, $actor): PerformanceRecord {
            $record = PerformanceRecord::query()->create($this->buildAttributes($payload, $actor));
            $this->audit($actor, 'performance_record', $record->id, 'created', null, $record->toArray());

            return $record->load(['professional', 'service.category']);
        });
    }

    public function update(PerformanceRecord $performanceRecord, array $payload, User $actor): PerformanceRecord
    {
        return DB::transaction(function () use ($performanceRecord, $payload, $actor): PerformanceRecord {
            $before = $performanceRecord->toArray();
            $performanceRecord->fill($this->buildAttributes($payload, $actor, $performanceRecord));
            $performanceRecord->save();
            $this->audit($actor, 'performance_record', $performanceRecord->id, 'updated', $before, $performanceRecord->fresh()->toArray());

            return $performanceRecord->load(['professional', 'service.category']);
        });
    }

    public function delete(PerformanceRecord $performanceRecord, User $actor): void
    {
        DB::transaction(function () use ($performanceRecord, $actor): void {
            $before = $performanceRecord->toArray();
            $performanceRecord->delete();
            $this->audit($actor, 'performance_record', $performanceRecord->id, 'deleted', $before, null);
        });
    }

    private function buildAttributes(array $payload, User $actor, ?PerformanceRecord $existing = null): array
    {
        $professional = Professional::query()->findOrFail($payload['professional_id']);
        $service = isset($payload['service_id']) ? Service::query()->with('category')->findOrFail($payload['service_id']) : null;

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
        $calculation = $this->calculationService->calculate(
            new PerformanceCalculationInput(
                calculationMode: CalculationMode::from($payload['calculation_mode']),
                quantity: (string) $payload['quantity'],
                unitAmount: (string) $payload['unit_amount'],
                percentageValue: isset($payload['percentage_value']) ? (string) $payload['percentage_value'] : null,
                fixedAmount: isset($payload['fixed_amount']) ? (string) $payload['fixed_amount'] : null,
            ),
        );

        $serviceName = $service?->display_name ?? ($payload['service_name'] ?? null);

        if ($serviceName === null || trim($serviceName) === '') {
            throw ValidationException::withMessages([
                'service_name' => 'Il nome della prestazione e obbligatorio quando non viene selezionata dal catalogo.',
            ]);
        }

        $manualArea = isset($payload['area_name']) ? trim((string) $payload['area_name']) : '';

        return [
            'performed_at' => $performedAt->toDateString(),
            'professional_id' => $professional->id,
            'professional_name_snapshot' => $professional->full_name,
            'category_name_snapshot' => $service?->category?->name ?: ($manualArea !== '' ? $manualArea : $professional->area_name),
            'service_id' => $service?->id,
            'service_name_snapshot' => $serviceName,
            'quantity' => $calculation->quantity,
            'unit_amount' => $calculation->unitAmount,
            'total_amount' => $calculation->totalAmount,
            'calculation_mode' => $payload['calculation_mode'],
            'percentage_value' => $calculation->percentageValue,
            'fixed_amount' => $calculation->fixedAmount,
            'professional_amount' => $calculation->professionalAmount,
            'center_amount' => $calculation->centerAmount,
            'payment_method' => PaymentMethod::tryFrom((string) ($payload['payment_method'] ?? ''))?->value
                ?? $existing?->payment_method?->value
                ?? PaymentMethod::Card->value,
            'is_invoiced' => (bool) ($payload['is_invoiced'] ?? $existing?->is_invoiced ?? false),
            'is_black' => (bool) ($payload['is_black'] ?? $existing?->is_black ?? false),
            'notes' => $payload['notes'] ?? null,
            'created_by' => $existing?->created_by ?? $actor->id,
            'updated_by' => $actor->id,
        ];
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
