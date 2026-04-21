<?php

namespace App\Services;

use App\Models\CountingPeriod;
use App\Models\PerformanceRecord;
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

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = PerformanceRecord::query()
            ->whereDate('performed_at', '>=', $resolved['start_date'])
            ->whereDate('performed_at', '<=', $resolved['end_date'])
            ->selectRaw('professional_id, professional_name_snapshot, COUNT(*) as performance_count, SUM(professional_amount) as professional_total, SUM(center_amount) as center_total')
            ->groupBy('professional_id', 'professional_name_snapshot')
            ->orderBy('professional_name_snapshot')
            ->get()
            ->map(fn ($row) => [
                'professional_id' => (int) $row->professional_id,
                'professional_name' => $row->professional_name_snapshot ?: 'Non specificato',
                'period_label' => $resolved['label'],
                'performance_count' => (int) $row->performance_count,
                'professional_total' => round((float) $row->professional_total, 2),
                'center_total' => round((float) $row->center_total, 2),
                'message' => sprintf('Totale da fatturare a Humancare Telemedicine S.r.l.: %s', $this->formatEuro((float) $row->professional_total)),
            ]);

        return [
            'period' => $resolved,
            'rows' => $rows->all(),
        ];
    }

    private function formatEuro(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }
}
