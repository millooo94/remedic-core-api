<?php

namespace App\Services;

use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use App\Models\ProfessionalTimeBlock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ProfessionalAvailabilityService
{
    public function manualRulesQuery(): Builder
    {
        return ProfessionalAvailabilityRule::query();
    }

    public function manualExceptionsQuery(): Builder
    {
        return ProfessionalAvailabilityException::query();
    }

    public function assertRuleDoesNotOverlap(array $payload, ?int $ignoreId = null): void
    {
        if (($payload['is_active'] ?? true) !== true) {
            return;
        }

        $overlapExists = $this->manualRulesQuery()
            ->where('professional_id', $payload['professional_id'])
            ->where('weekday', $payload['weekday'])
            ->where('is_active', true)
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('start_time', '<', $payload['end_time'])
            ->where('end_time', '>', $payload['start_time'])
            ->where(function (Builder $query) use ($payload): void {
                if (! empty($payload['valid_until'])) {
                    $query
                        ->whereNull('valid_from')
                        ->orWhereDate('valid_from', '<=', $payload['valid_until']);

                    return;
                }

                $query->whereRaw('1 = 1');
            })
            ->where(function (Builder $query) use ($payload): void {
                if (! empty($payload['valid_from'])) {
                    $query
                        ->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', $payload['valid_from']);

                    return;
                }

                $query->whereRaw('1 = 1');
            })
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'start_time' => 'Esiste gia una disponibilita ricorrente sovrapposta per questo professionista.',
            ]);
        }
    }

    public function assertExceptionDoesNotOverlap(array $payload, ?int $ignoreId = null): void
    {
        $query = $this->manualExceptionsQuery()
            ->where('professional_id', $payload['professional_id'])
            ->whereDate('date', $payload['date'])
            ->where('type', $payload['type'])
            ->when($ignoreId, fn (Builder $builder) => $builder->whereKeyNot($ignoreId));

        if (empty($payload['start_time']) || empty($payload['end_time'])) {
            $overlapExists = $query->exists();
        } else {
            $overlapExists = $query
                ->where(function (Builder $builder) use ($payload): void {
                    $builder
                        ->whereNull('start_time')
                        ->orWhereNull('end_time')
                        ->orWhere(function (Builder $timeQuery) use ($payload): void {
                            $timeQuery
                                ->where('start_time', '<', $payload['end_time'])
                                ->where('end_time', '>', $payload['start_time']);
                        });
                })
                ->exists();
        }

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'start_time' => 'Esiste gia una eccezione giornaliera sovrapposta per questo professionista.',
            ]);
        }
    }

    public function assertBlockDoesNotOverlap(array $payload, ?int $ignoreId = null): void
    {
        $overlapExists = ProfessionalTimeBlock::query()
            ->where('professional_id', $payload['professional_id'])
            ->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $payload['ends_at'])
            ->where('ends_at', '>', $payload['starts_at'])
            ->exists();

        if ($overlapExists) {
            throw ValidationException::withMessages([
                'starts_at' => 'Esiste gia un blocco sovrapposto per questo professionista.',
            ]);
        }
    }
}
