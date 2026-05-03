<?php

namespace App\Services;

use App\Enums\PerformanceSplitMode;
use App\Enums\PerformanceSplitSubjectType;
use App\Models\CountingPeriod;
use App\Models\PerformanceRecord;
use App\Models\PerformanceRecordSplit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CountingPeriodService
{
    public function resolvePeriod(?CountingPeriod $period = null, ?string $startDate = null, ?string $endDate = null): array
    {
        if ($period !== null) {
            return [
                'label' => $period->label,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
            ];
        }

        $start = Carbon::parse((string) $startDate)->startOfDay();
        $end = Carbon::parse((string) $endDate)->endOfDay();

        return [
            'label' => sprintf('%s - %s', $start->format('d/m/Y'), $end->format('d/m/Y')),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
    }

    public function summary(?CountingPeriod $period = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $resolved = $this->resolvePeriod($period, $startDate, $endDate);

        /** @var Collection<int, PerformanceRecord> $records */
        $records = PerformanceRecord::query()
            ->with('splits')
            ->whereDate('performed_at', '>=', $resolved['start_date'])
            ->whereDate('performed_at', '<=', $resolved['end_date'])
            ->where('is_invoiced', false)
            ->get();

        $rows = $this->allocationRows($records)
            ->groupBy(fn (array $row) => ! empty($row['professional_id']) ? 'id:'.$row['professional_id'] : 'name:'.strtolower($row['professional_name']))
            ->map(fn (Collection $group) => [
                'professional_id' => (int) ($group->first()['professional_id'] ?? 0),
                'professional_name' => $group->first()['professional_name'] ?: 'Non specificato',
                'period_label' => $resolved['label'],
                'performance_count' => (int) $group->sum('performance_count'),
                'professional_total' => round((float) $group->sum('professional_total'), 2),
                'center_total' => round((float) $group->sum('center_total'), 2),
                'message' => sprintf('Totale quote professionista da fatturare: %s', $this->formatEuro((float) $group->sum('professional_total'))),
            ])
            ->sortBy('professional_name')
            ->values();

        return [
            'period' => $resolved,
            'rows' => $rows->all(),
        ];
    }

    private function allocationRows(Collection $records): Collection
    {
        $rows = collect();

        foreach ($records as $record) {
            $splitMode = $record->split_mode?->value ?? $record->split_mode;
            if ($splitMode === PerformanceSplitMode::Advanced->value) {
                /** @var Collection<int, PerformanceRecordSplit> $splits */
                $splits = $record->relationLoaded('splits') ? $record->splits : $record->splits()->get();
                $professionalSplits = $splits
                    ->filter(fn (PerformanceRecordSplit $split) => ($split->subject_type?->value ?? $split->subject_type) === PerformanceSplitSubjectType::Professional->value)
                    ->groupBy(fn (PerformanceRecordSplit $split) => $split->professional_id ?: ('name:'.strtolower((string) $split->professional_name_snapshot)));

                $professionalTotal = max(0.0, (float) $record->professional_amount);
                foreach ($professionalSplits as $group) {
                    /** @var PerformanceRecordSplit|null $first */
                    $first = $group->first();
                    $allocated = round((float) $group->sum('amount'), 2);
                    $ratio = $professionalTotal > 0 ? ($allocated / $professionalTotal) : 0;

                    $rows->push([
                        'professional_id' => $first?->professional_id,
                        'professional_name' => trim((string) ($first?->professional_name_snapshot ?: 'Non specificato')) ?: 'Non specificato',
                        'performance_count' => (int) $record->quantity,
                        'professional_total' => $allocated,
                        'center_total' => round((float) $record->center_amount * $ratio, 2),
                    ]);
                }

                continue;
            }

            $rows->push([
                'professional_id' => $record->professional_id,
                'professional_name' => trim((string) ($record->professional_name_snapshot ?: 'Non specificato')) ?: 'Non specificato',
                'performance_count' => (int) $record->quantity,
                'professional_total' => round((float) $record->professional_amount, 2),
                'center_total' => round((float) $record->center_amount, 2),
            ]);
        }

        return $rows;
    }

    private function formatEuro(float $amount): string
    {
        return "\u{20AC} ".number_format($amount, 2, ',', '.');
    }
}
