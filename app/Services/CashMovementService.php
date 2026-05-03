<?php

namespace App\Services;

use App\Enums\CashBoxType;
use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Mail\CashMovementDeletedWarningMail;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\PerformanceRecord;
use App\Support\Numbers\ScaledNumber;
use App\Models\User;
use App\Support\Filters\CashMovementFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashMovementService
{
    public function __construct(
        private readonly CashMovementFilters $filters,
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        $query = CashMovement::query();

        $this->filters->apply($query, $filters);

        return $this->filters->applySort($query, $filters['sort'] ?? null);
    }

    public function summary(array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $fatturati = $this->buildBoxSummary(CashBoxType::Fatturati, $dateFrom, $dateTo);
        $black = $this->buildBoxSummary(CashBoxType::Black, $dateFrom, $dateTo);

        return [
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'boxes' => [
                CashBoxType::Fatturati->value => $fatturati,
                CashBoxType::Black->value => $black,
            ],
            'totals' => [
                'current_balance' => $this->formatDecimal((float) $fatturati['current_balance'] + (float) $black['current_balance']),
                'period_deposits' => $this->formatDecimal((float) $fatturati['period_deposits'] + (float) $black['period_deposits']),
                'period_withdrawals' => $this->formatDecimal((float) $fatturati['period_withdrawals'] + (float) $black['period_withdrawals']),
                'period_net' => $this->formatDecimal((float) $fatturati['period_net'] + (float) $black['period_net']),
                'period_movements_count' => $fatturati['period_movements_count'] + $black['period_movements_count'],
            ],
        ];
    }

    public function create(array $payload, User $actor): CashMovement
    {
        return DB::transaction(function () use ($payload, $actor): CashMovement {
            $movement = CashMovement::query()->create($this->buildAttributes($payload, $actor));
            $this->recalculateBalancesForBox($this->resolveCashBoxType($movement->cash_box_type), 'amount');
            $movement = $movement->fresh();

            $this->audit($actor, 'cash_movement', $movement->id, 'created', null, $movement->toArray());

            return $movement;
        });
    }

    public function update(CashMovement $cashMovement, array $payload, User $actor): CashMovement
    {
        return DB::transaction(function () use ($cashMovement, $payload, $actor): CashMovement {
            $before = $cashMovement->toArray();
            $originalBox = $this->resolveCashBoxType($cashMovement->cash_box_type);

            $cashMovement->fill($this->buildAttributes($payload, $actor, $cashMovement));
            $cashMovement->save();

            $targetBox = $this->resolveCashBoxType($cashMovement->cash_box_type);
            $this->recalculateBalancesForBox($originalBox, 'amount');

            if ($targetBox !== $originalBox) {
                $this->recalculateBalancesForBox($targetBox, 'amount');
            }

            $fresh = $cashMovement->fresh();
            $this->audit($actor, 'cash_movement', $fresh->id, 'updated', $before, $fresh->toArray());

            return $fresh;
        });
    }

    public function delete(CashMovement $cashMovement, User $actor): void
    {
        $warningPayload = DB::transaction(function () use ($cashMovement, $actor): array {
            $before = $this->deleteExistingMovement($cashMovement, $actor, 'movement');

            return $this->warningPayloadForDeletedMovement($before, $actor);
        });

        $this->sendDeletionWarning($warningPayload);
    }

    public function reset(User $actor): int
    {
        return DB::transaction(function () use ($actor): int {
            $summary = [
                'count' => CashMovement::query()->count(),
                'total_amount' => $this->formatDecimal((float) (CashMovement::query()->sum('amount') ?? 0)),
            ];

            CashMovement::query()->delete();

            $this->audit($actor, 'cash_movement', null, 'reset', $summary, null);

            return (int) $summary['count'];
        });
    }

    public function syncFromPerformanceRecord(PerformanceRecord $performanceRecord, User $actor): ?CashMovement
    {
        return DB::transaction(function () use ($performanceRecord, $actor): ?CashMovement {
            $existing = $this->findPerformanceRecordMovement($performanceRecord);

            if ($performanceRecord->payment_method !== PaymentMethod::Cash) {
                if ($existing !== null) {
                    $this->deleteExistingMovement($existing, $actor, 'movement');
                }

                return null;
            }

            $attributes = $this->buildPerformanceRecordAttributes($performanceRecord, $actor, $existing);

            if ($existing === null) {
                $movement = CashMovement::query()->create($attributes);
                $this->recalculateBalancesForBox($this->resolveCashBoxType($movement->cash_box_type), 'movement');
                $fresh = $movement->fresh();

                $this->audit($actor, 'cash_movement', $fresh->id, 'created', null, $fresh->toArray());

                return $fresh;
            }

            $before = $existing->toArray();
            $originalBox = $this->resolveCashBoxType($existing->cash_box_type);

            $existing->fill($attributes);
            $existing->save();

            $targetBox = $this->resolveCashBoxType($existing->cash_box_type);
            $this->recalculateBalancesForBox($originalBox, 'movement');

            if ($targetBox !== $originalBox) {
                $this->recalculateBalancesForBox($targetBox, 'movement');
            }

            $fresh = $existing->fresh();
            $this->audit($actor, 'cash_movement', $fresh->id, 'updated', $before, $fresh->toArray());

            return $fresh;
        });
    }

    public function deleteForPerformanceRecord(PerformanceRecord $performanceRecord, User $actor): void
    {
        DB::transaction(function () use ($performanceRecord, $actor): void {
            $existing = $this->findPerformanceRecordMovement($performanceRecord);

            if ($existing !== null) {
                $this->deleteExistingMovement($existing, $actor, 'movement');
            }
        });
    }

    private function buildAttributes(array $payload, User $actor, ?CashMovement $existing = null): array
    {
        $movementDate = Carbon::parse($payload['movement_date']);

        return [
            'movement_date' => $movementDate->toDateString(),
            'movement_type' => $this->resolveMovementType($payload['movement_type'])->value,
            'cash_box_type' => $this->resolveCashBoxType($payload['cash_box_type'])->value,
            'counterparty_name' => $this->resolveCounterpartyName($payload, $existing),
            'amount' => $this->formatDecimal(
                ScaledNumber::toScaledInteger($payload['amount'], 2, 'amount', true, 'Valore obbligatorio.', 'Valore numerico non valido.') / 100,
            ),
            'reason' => $this->resolveNullableText($payload, 'reason', $existing?->reason, 190),
            'notes' => $this->resolveNullableText($payload, 'notes', $existing?->notes, 2000),
            'source_performance_record_id' => $existing?->source_performance_record_id,
            'created_by' => $existing?->created_by ?? $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function buildPerformanceRecordAttributes(PerformanceRecord $performanceRecord, User $actor, ?CashMovement $existing = null): array
    {
        $serviceName = trim((string) ($performanceRecord->service_name_snapshot ?? ''));
        $professionalName = trim((string) ($performanceRecord->professional_name_snapshot ?? ''));
        $performedAt = $performanceRecord->performed_at?->toDateString() ?? now()->toDateString();
        $reason = $serviceName !== '' ? "Incasso prestazione: {$serviceName}" : 'Incasso prestazione';
        $noteParts = array_filter([
            "Generato automaticamente dalla prestazione effettuata #{$performanceRecord->id}.",
            $professionalName !== '' ? "Professionista: {$professionalName}." : null,
        ]);

        return [
            'movement_date' => $performedAt,
            'movement_type' => CashMovementType::Versamento->value,
            'cash_box_type' => ($performanceRecord->is_black ? CashBoxType::Black : CashBoxType::Fatturati)->value,
            'counterparty_name' => Str::limit($professionalName !== '' ? $professionalName : "Prestazione #{$performanceRecord->id}", 190),
            'amount' => $this->formatDecimal((float) $performanceRecord->total_amount),
            'reason' => Str::limit($reason, 190),
            'notes' => Str::limit(implode(' ', $noteParts), 2000),
            'source_performance_record_id' => $performanceRecord->id,
            'created_by' => $existing?->created_by ?? $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function buildBoxSummary(CashBoxType $box, ?string $dateFrom, ?string $dateTo): array
    {
        $periodRow = CashMovement::query()
            ->where('cash_box_type', $box->value)
            ->when($dateFrom, fn (Builder $builder) => $builder->whereDate('movement_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $builder) => $builder->whereDate('movement_date', '<=', $dateTo))
            ->selectRaw('COUNT(*) as period_movements_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN movement_type = ? THEN amount ELSE 0 END), 0) as period_deposits',
                [CashMovementType::Versamento->value],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN movement_type = ? THEN amount ELSE 0 END), 0) as period_withdrawals',
                [CashMovementType::Prelievo->value],
            )
            ->first();

        $currentBalance = CashMovement::query()
            ->where('cash_box_type', $box->value)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->value('balance_after');

        $periodDeposits = (float) ($periodRow?->period_deposits ?? 0);
        $periodWithdrawals = (float) ($periodRow?->period_withdrawals ?? 0);

        return [
            'cash_box_type' => $box->value,
            'label' => $box->label(),
            'current_balance' => $this->formatDecimal((float) ($currentBalance ?? 0)),
            'period_deposits' => $this->formatDecimal($periodDeposits),
            'period_withdrawals' => $this->formatDecimal($periodWithdrawals),
            'period_net' => $this->formatDecimal($periodDeposits - $periodWithdrawals),
            'period_movements_count' => (int) ($periodRow?->period_movements_count ?? 0),
        ];
    }

    private function recalculateBalancesForBox(CashBoxType $cashBoxType, string $errorKey): void
    {
        $runningBalance = 0.0;

        $movements = CashMovement::query()
            ->where('cash_box_type', $cashBoxType->value)
            ->orderBy('movement_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            $movementType = $this->resolveMovementType($movement->movement_type);
            $delta = $movementType->sign() * (float) $movement->amount;
            $nextBalance = round($runningBalance + $delta, 2);

            if ($nextBalance < -0.00001) {
                throw ValidationException::withMessages([
                    $errorKey => $this->negativeBalanceMessage($cashBoxType, $movement),
                ]);
            }

            $formattedBalance = $this->formatDecimal($nextBalance);

            if ($movement->balance_after !== $formattedBalance) {
                $movement->forceFill(['balance_after' => $formattedBalance])->save();
            }

            $runningBalance = $nextBalance;
        }
    }

    private function negativeBalanceMessage(CashBoxType $cashBoxType, CashMovement $movement): string
    {
        $dateLabel = $movement->movement_date?->format('d/m/Y') ?? 'data selezionata';

        return "Operazione non consentita: il saldo della {$cashBoxType->label()} andrebbe in negativo il {$dateLabel}.";
    }

    private function resolveCashBoxType(CashBoxType|string $value): CashBoxType
    {
        return $value instanceof CashBoxType ? $value : CashBoxType::from((string) $value);
    }

    private function resolveMovementType(CashMovementType|string $value): CashMovementType
    {
        return $value instanceof CashMovementType ? $value : CashMovementType::from((string) $value);
    }

    private function formatDecimal(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function deleteExistingMovement(CashMovement $cashMovement, User $actor, string $errorKey): array
    {
        $before = $cashMovement->toArray();
        $box = $this->resolveCashBoxType($cashMovement->cash_box_type);

        $cashMovement->delete();
        $this->recalculateBalancesForBox($box, $errorKey);

        $this->audit($actor, 'cash_movement', $cashMovement->id, 'deleted', $before, null);

        return $before;
    }

    private function findPerformanceRecordMovement(PerformanceRecord $performanceRecord): ?CashMovement
    {
        return CashMovement::query()
            ->where('source_performance_record_id', $performanceRecord->id)
            ->first();
    }

    private function resolveCounterpartyName(array $payload, ?CashMovement $existing = null): string
    {
        $explicitValue = array_key_exists('counterparty_name', $payload)
            ? trim((string) ($payload['counterparty_name'] ?? ''))
            : null;

        if ($explicitValue !== null && $explicitValue !== '') {
            return Str::limit($explicitValue, 190);
        }

        if ($existing !== null && $this->hasCustomCounterpartyName($existing)) {
            return $existing->counterparty_name;
        }

        $fallback = trim((string) ($payload['reason'] ?? $existing?->reason ?? ''));

        return $fallback !== ''
            ? Str::limit($fallback, 190)
            : 'Movimento manuale';
    }

    private function hasCustomCounterpartyName(CashMovement $existing): bool
    {
        $counterpartyName = trim((string) ($existing->counterparty_name ?? ''));
        $reason = trim((string) ($existing->reason ?? ''));

        return $counterpartyName !== ''
            && $counterpartyName !== 'Movimento manuale'
            && ($reason === '' || $counterpartyName !== $reason);
    }

    private function resolveNullableText(array $payload, string $key, ?string $existingValue, int $maxLength): ?string
    {
        if (! array_key_exists($key, $payload)) {
            return $existingValue;
        }

        $value = trim((string) ($payload[$key] ?? ''));

        return $value !== '' ? Str::limit($value, $maxLength) : null;
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

    private function warningPayloadForDeletedMovement(array $before, User $actor): array
    {
        $deletedAt = now();
        $movementType = CashMovementType::tryFrom((string) ($before['movement_type'] ?? '')) ?? CashMovementType::Versamento;
        $cashBoxType = CashBoxType::tryFrom((string) ($before['cash_box_type'] ?? '')) ?? CashBoxType::Fatturati;
        $actorName = trim((string) ($actor->name ?? ''));
        $fallbackActorName = trim((string) (($actor->first_name ?? '').' '.($actor->last_name ?? '')));
        $resolvedActorName = $actorName !== '' ? $actorName : ($fallbackActorName !== '' ? $fallbackActorName : 'Utente non specificato');
        $reason = trim((string) ($before['reason'] ?? ''));
        $notes = trim((string) ($before['notes'] ?? ''));

        return [
            'movement_id' => (int) ($before['id'] ?? 0),
            'deleted_at_label' => $deletedAt->format('d/m/Y H:i:s'),
            'movement_type_label' => $movementType->label(),
            'cash_box_label' => $cashBoxType->label(),
            'amount_label' => '€ '.$this->formatDecimal((float) ($before['amount'] ?? 0)),
            'reason_label' => $reason !== '' ? $reason : 'Non indicata',
            'notes_label' => $notes !== '' ? $notes : 'Nessuna nota',
            'actor_label' => trim($resolvedActorName.' <'.($actor->email ?? 'email non disponibile').'>'),
        ];
    }

    private function sendDeletionWarning(array $warningPayload): void
    {
        $recipients = config('mail.cash_warning_recipients', []);

        if (! is_array($recipients) || $recipients === []) {
            return;
        }

        Mail::to($recipients)->send(new CashMovementDeletedWarningMail($warningPayload));
    }
}
