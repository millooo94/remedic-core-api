<?php

namespace App\Services;

use App\Models\ExpenseRecord;
use Illuminate\Support\Carbon;

class WeeklyCenterSummaryService
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {
    }

    public function buildSummary(?Carbon $referenceDate = null): array
    {
        $anchor = ($referenceDate ?? now())->copy()->startOfDay();
        $weekEnd = $anchor->copy()->startOfWeek(Carbon::MONDAY)->subDay()->endOfDay();
        $weekStart = $weekEnd->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        $summary = $this->dashboardService->summary([
            'date_from' => $weekStart->toDateString(),
            'date_to' => $weekEnd->toDateString(),
        ]);

        $cards = $summary['cards'];
        $weeklyCosts = ExpenseRecord::query()
            ->whereDate('expense_date', '>=', $weekStart->toDateString())
            ->whereDate('expense_date', '<=', $weekEnd->toDateString())
            ->get();
        $weeklyFixedCosts = (float) $weeklyCosts
            ->filter(fn (ExpenseRecord $record) => ($record->type?->value ?? $record->type) === 'fixed')
            ->sum('amount');
        $weeklyVariableCosts = (float) $weeklyCosts
            ->filter(fn (ExpenseRecord $record) => ($record->type?->value ?? $record->type) === 'variable')
            ->sum('amount');
        $weeklyTotalCenterCosts = $weeklyFixedCosts + $weeklyVariableCosts;
        $weeklyNetCenterMargin = (float) ($cards['total_revenue_amount'] ?? 0) - $weeklyTotalCenterCosts;

        return [
            'period' => [
                'label' => sprintf(
                    '%s - %s',
                    $weekStart->translatedFormat('d/m/Y'),
                    $weekEnd->translatedFormat('d/m/Y'),
                ),
                'start_date' => $weekStart->toDateString(),
                'end_date' => $weekEnd->toDateString(),
            ],
            'kpis' => [
                'total_performances' => (int) ($cards['total_performances'] ?? 0),
                'total_revenue_amount' => (float) ($cards['total_revenue_amount'] ?? 0),
                'total_professional_amount' => (float) ($cards['total_professional_amount'] ?? 0),
                'total_center_amount' => (float) ($cards['total_center_amount'] ?? 0),
                'total_fixed_costs' => round($weeklyFixedCosts, 2),
                'total_variable_costs' => round($weeklyVariableCosts, 2),
                'total_center_costs' => round($weeklyTotalCenterCosts, 2),
                'net_center_margin' => round($weeklyNetCenterMargin, 2),
                'black_center_net' => (float) ($cards['black'] ?? 0),
            ],
        ];
    }
}
