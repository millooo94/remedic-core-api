<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\ExpenseRecord;
use App\Models\PerformanceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public function summary(array $filters): array
    {
        [$startDate, $endDate] = $this->resolveRange($filters);
        [$previousStartDate, $previousEndDate, $comparisonBasis] = $this->resolvePreviousRange($filters, $startDate, $endDate);

        $performanceRecords = $this->performanceRecordsForRange($startDate, $endDate);
        $previousPerformanceRecords = $this->performanceRecordsForRange($previousStartDate, $previousEndDate);

        $expenseRecords = $this->expenseRecordsForRange($startDate, $endDate);
        $previousExpenseRecords = $this->expenseRecordsForRange($previousStartDate, $previousEndDate);

        $fixedCosts = round((float) $expenseRecords->where('type', 'fixed')->sum('amount'), 2);
        $variableCosts = round((float) $expenseRecords->where('type', 'variable')->sum('amount'), 2);
        $centerTotal = round((float) $performanceRecords->sum('center_amount'), 2);
        $professionalTotal = round((float) $performanceRecords->sum('professional_amount'), 2);
        $revenueTotal = round((float) $performanceRecords->sum('total_amount'), 2);
        $cashRevenueTotal = round((float) $performanceRecords->where('payment_method', PaymentMethod::Cash)->sum('total_amount'), 2);
        $cardRevenueTotal = round((float) $performanceRecords->where('payment_method', PaymentMethod::Card)->sum('total_amount'), 2);
        $totalPerformances = $performanceRecords->count();
        $averagePerformanceCost = $totalPerformances > 0
            ? round($revenueTotal / $totalPerformances, 2)
            : 0.0;
        $blackCenterNet = round((float) $performanceRecords->where('is_black', true)->sum('center_amount'), 2);
        $totalCenterCosts = round($fixedCosts + $variableCosts, 2);
        $netCenterMargin = round($centerTotal - $totalCenterCosts, 2);

        $previousFixedCosts = round((float) $previousExpenseRecords->where('type', 'fixed')->sum('amount'), 2);
        $previousVariableCosts = round((float) $previousExpenseRecords->where('type', 'variable')->sum('amount'), 2);
        $previousCenterTotal = round((float) $previousPerformanceRecords->sum('center_amount'), 2);
        $previousProfessionalTotal = round((float) $previousPerformanceRecords->sum('professional_amount'), 2);
        $previousRevenueTotal = round((float) $previousPerformanceRecords->sum('total_amount'), 2);
        $previousTotalPerformances = $previousPerformanceRecords->count();
        $previousBlackCenterNet = round((float) $previousPerformanceRecords->where('is_black', true)->sum('center_amount'), 2);
        $previousTotalCenterCosts = round($previousFixedCosts + $previousVariableCosts, 2);
        $previousNetCenterMargin = round($previousCenterTotal - $previousTotalCenterCosts, 2);

        $topByCount = $performanceRecords
            ->groupBy(fn (PerformanceRecord $record) => $record->professional_name_snapshot ?: 'Non specificato')
            ->map(fn (Collection $group, string $name): array => [
                'professional_name' => $name ?: 'Non specificato',
                'performances' => (int) $group->count(),
            ])
            ->sortByDesc('performances')
            ->values()
            ->first();

        $topByRevenue = $performanceRecords
            ->groupBy(fn (PerformanceRecord $record) => $record->professional_name_snapshot ?: 'Non specificato')
            ->map(fn (Collection $group, string $name): array => [
                'professional_name' => $name ?: 'Non specificato',
                'revenue_total' => round((float) $group->sum('total_amount'), 2),
            ])
            ->sortByDesc('revenue_total')
            ->values()
            ->first();

        return [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'previous_start_date' => $previousStartDate,
                'previous_end_date' => $previousEndDate,
                'comparison_basis' => $comparisonBasis,
            ],
            'cards' => [
                'total_performances' => $totalPerformances,
                'total_center_amount' => $centerTotal,
                'total_professional_amount' => $professionalTotal,
                'total_revenue_amount' => $revenueTotal,
                'revenue_payment_breakdown' => [
                    'cash' => $cashRevenueTotal,
                    'card' => $cardRevenueTotal,
                ],
                'total_fixed_costs' => $fixedCosts,
                'total_variable_costs' => $variableCosts,
                'average_performance_cost' => $averagePerformanceCost,
                'black' => $blackCenterNet,
                'total_center_costs' => $totalCenterCosts,
                'net_center_margin' => $netCenterMargin,
                'top_by_performance_count' => $topByCount,
                'top_by_revenue' => $topByRevenue,
                'comparisons' => [
                    'total_performances' => $this->buildComparison($totalPerformances, $previousTotalPerformances, 0),
                    'total_revenue_amount' => $this->buildComparison($revenueTotal, $previousRevenueTotal),
                    'total_center_amount' => $this->buildComparison($centerTotal, $previousCenterTotal),
                    'total_professional_amount' => $this->buildComparison($professionalTotal, $previousProfessionalTotal),
                    'total_fixed_costs' => $this->buildComparison($fixedCosts, $previousFixedCosts),
                    'total_variable_costs' => $this->buildComparison($variableCosts, $previousVariableCosts),
                    'total_center_costs' => $this->buildComparison($totalCenterCosts, $previousTotalCenterCosts),
                    'net_center_margin' => $this->buildComparison($netCenterMargin, $previousNetCenterMargin),
                    'average_performance_cost' => $this->buildComparison($averagePerformanceCost, $this->averageForComparison($previousRevenueTotal, $previousTotalPerformances)),
                    'black' => $this->buildComparison($blackCenterNet, $previousBlackCenterNet),
                ],
            ],
        ];
    }

    public function monthlyTrends(array $filters): array
    {
        [$startDate, $endDate] = $this->resolveRange($filters);

        $performanceRecords = PerformanceRecord::query()
            ->whereDate('performed_at', '>=', $startDate)
            ->whereDate('performed_at', '<=', $endDate)
            ->get();

        $expenseRecords = ExpenseRecord::query()
            ->with('category')
            ->whereDate('expense_date', '>=', $startDate)
            ->whereDate('expense_date', '<=', $endDate)
            ->get();

        $months = collect();
        $cursor = Carbon::parse($startDate)->startOfMonth();
        $end = Carbon::parse($endDate)->endOfMonth();

        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $months->put($key, [
                'key' => $key,
                'label' => $cursor->translatedFormat('M Y'),
                'center_amount' => 0.0,
                'professional_amount' => 0.0,
                'fixed_costs' => 0.0,
                'variable_costs' => 0.0,
                'net_margin' => 0.0,
            ]);

            $cursor->addMonth();
        }

        foreach ($performanceRecords as $record) {
            $key = $record->performed_at?->format('Y-m');

            if ($key === null || ! $months->has($key)) {
                continue;
            }

            $row = $months->get($key);
            $row['center_amount'] += (float) $record->center_amount;
            $row['professional_amount'] += (float) $record->professional_amount;
            $months->put($key, $row);
        }

        foreach ($expenseRecords as $record) {
            $key = sprintf('%04d-%02d', $record->competence_year, $record->competence_month);

            if (! $months->has($key)) {
                continue;
            }

            $row = $months->get($key);

            if ($record->type->value === 'fixed') {
                $row['fixed_costs'] += (float) $record->amount;
            } else {
                $row['variable_costs'] += (float) $record->amount;
            }

            $months->put($key, $row);
        }

        $trends = $months->map(function (array $row): array {
            $row['center_amount'] = round($row['center_amount'], 2);
            $row['professional_amount'] = round($row['professional_amount'], 2);
            $row['fixed_costs'] = round($row['fixed_costs'], 2);
            $row['variable_costs'] = round($row['variable_costs'], 2);
            $row['net_margin'] = round($row['center_amount'] - ($row['fixed_costs'] + $row['variable_costs']), 2);

            return $row;
        })->values()->all();

        return [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'monthly_trends' => $trends,
            'professional_split' => $this->professionalSplit($performanceRecords),
            'expense_category_split' => $this->expenseCategorySplit($expenseRecords),
        ];
    }

    private function professionalSplit(Collection $records): array
    {
        return $records
            ->groupBy(fn (PerformanceRecord $record) => $record->professional_name_snapshot ?: 'Non specificato')
            ->map(fn (Collection $group, string $name) => [
                'label' => $name ?: 'Non specificato',
                'total' => round((float) $group->sum('professional_amount'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function expenseCategorySplit(Collection $records): array
    {
        return $records
            ->groupBy(fn (ExpenseRecord $record) => $record->category?->name ?: 'Non specificato')
            ->map(fn (Collection $group, string $name) => [
                'label' => $name ?: 'Non specificato',
                'total' => round((float) $group->sum('amount'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function resolveRange(array $filters): array
    {
        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            return [
                Carbon::parse($filters['date_from'])->toDateString(),
                Carbon::parse($filters['date_to'])->toDateString(),
            ];
        }

        if (! empty($filters['year']) && ! empty($filters['month'])) {
            $start = Carbon::create((int) $filters['year'], (int) $filters['month'], 1)->startOfMonth();

            return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        }

        if (! empty($filters['year'])) {
            $start = Carbon::create((int) $filters['year'], 1, 1)->startOfYear();

            return [$start->toDateString(), $start->copy()->endOfYear()->toDateString()];
        }

        $start = now()->startOfYear();

        return [$start->toDateString(), now()->endOfMonth()->toDateString()];
    }

    private function resolvePreviousRange(array $filters, string $startDate, string $endDate): array
    {
        $isMonthly = ! empty($filters['month']) && ! empty($filters['year']) && empty($filters['date_from']) && empty($filters['date_to']);
        if ($isMonthly) {
            $start = Carbon::parse($startDate)->startOfMonth();
            $previousMonthStart = $start->copy()->subMonth()->startOfMonth();
            $previousMonthEnd = $previousMonthStart->copy()->endOfMonth();

            return [$previousMonthStart->toDateString(), $previousMonthEnd->toDateString(), 'previous_month'];
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $days = $start->diffInDays($end) + 1;

        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [$previousStart->toDateString(), $previousEnd->toDateString(), 'previous_period'];
    }

    private function performanceRecordsForRange(string $startDate, string $endDate): Collection
    {
        return PerformanceRecord::query()
            ->whereDate('performed_at', '>=', $startDate)
            ->whereDate('performed_at', '<=', $endDate)
            ->get();
    }

    private function expenseRecordsForRange(string $startDate, string $endDate): Collection
    {
        return ExpenseRecord::query()
            ->whereDate('expense_date', '>=', $startDate)
            ->whereDate('expense_date', '<=', $endDate)
            ->get();
    }

    private function averageForComparison(float $revenueTotal, int $totalPerformances): float
    {
        if ($totalPerformances <= 0) {
            return 0.0;
        }

        return round($revenueTotal / $totalPerformances, 2);
    }

    private function buildComparison(float|int $current, float|int $previous, int $precision = 2): array
    {
        $currentValue = round((float) $current, $precision);
        $previousValue = round((float) $previous, $precision);
        $delta = round($currentValue - $previousValue, $precision);
        $deltaPercent = null;

        if (abs($previousValue) > 0.00001) {
            $deltaPercent = round(($delta / $previousValue) * 100, 2);
        }

        return [
            'current' => $currentValue,
            'previous' => $previousValue,
            'delta' => $delta,
            'delta_percent' => $deltaPercent,
        ];
    }
}
