<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\ExpenseRecordCompetence;
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

        $expenseAllocations = $this->expenseAllocationsForRange($startDate, $endDate);
        $previousExpenseAllocations = $this->expenseAllocationsForRange($previousStartDate, $previousEndDate);

        $fixedCosts = round((float) $expenseAllocations
            ->filter(fn (ExpenseRecordCompetence $allocation) => ($allocation->expenseRecord?->type?->value ?? $allocation->expenseRecord?->type) === 'fixed')
            ->sum('allocated_amount'), 2);
        $variableCosts = round((float) $expenseAllocations
            ->filter(fn (ExpenseRecordCompetence $allocation) => ($allocation->expenseRecord?->type?->value ?? $allocation->expenseRecord?->type) === 'variable')
            ->sum('allocated_amount'), 2);
        $centerTotal = round((float) $performanceRecords->sum('center_amount'), 2);
        $professionalTotal = round((float) $performanceRecords->sum('professional_amount'), 2);
        $revenueTotal = round((float) $performanceRecords->sum('total_amount'), 2);
        $cashRevenueTotal = round((float) $performanceRecords->where('payment_method', PaymentMethod::Cash)->sum('total_amount'), 2);
        $cardRevenueTotal = round((float) $performanceRecords->where('payment_method', PaymentMethod::Card)->sum('total_amount'), 2);
        $totalPerformances = (int) $performanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $averagePerformanceCost = $totalPerformances > 0
            ? round($revenueTotal / $totalPerformances, 2)
            : 0.0;
        $nonBlackPerformanceRecords = $performanceRecords->where('is_black', false);
        $nonBlackRevenueTotal = round((float) $nonBlackPerformanceRecords->sum('total_amount'), 2);
        $nonBlackPerformanceCount = (int) $nonBlackPerformanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $averagePerformanceCostExcludingBlack = $nonBlackPerformanceCount > 0
            ? round($nonBlackRevenueTotal / $nonBlackPerformanceCount, 2)
            : 0.0;
        $blackCenterNet = round((float) $performanceRecords->where('is_black', true)->sum('center_amount'), 2);
        $totalCenterCosts = round($fixedCosts + $variableCosts, 2);
        $netCenterMargin = round($revenueTotal - $totalCenterCosts, 2);

        $previousFixedCosts = round((float) $previousExpenseAllocations
            ->filter(fn (ExpenseRecordCompetence $allocation) => ($allocation->expenseRecord?->type?->value ?? $allocation->expenseRecord?->type) === 'fixed')
            ->sum('allocated_amount'), 2);
        $previousVariableCosts = round((float) $previousExpenseAllocations
            ->filter(fn (ExpenseRecordCompetence $allocation) => ($allocation->expenseRecord?->type?->value ?? $allocation->expenseRecord?->type) === 'variable')
            ->sum('allocated_amount'), 2);
        $previousCenterTotal = round((float) $previousPerformanceRecords->sum('center_amount'), 2);
        $previousProfessionalTotal = round((float) $previousPerformanceRecords->sum('professional_amount'), 2);
        $previousRevenueTotal = round((float) $previousPerformanceRecords->sum('total_amount'), 2);
        $previousTotalPerformances = (int) $previousPerformanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $previousBlackCenterNet = round((float) $previousPerformanceRecords->where('is_black', true)->sum('center_amount'), 2);
        $previousTotalCenterCosts = round($previousFixedCosts + $previousVariableCosts, 2);
        $previousNetCenterMargin = round($previousRevenueTotal - $previousTotalCenterCosts, 2);

        $performanceCountRanking = $this->performanceCountRanking($performanceRecords);
        $revenueRanking = $this->revenueRanking($performanceRecords);
        $topByCount = $performanceCountRanking[0] ?? null;
        $topByRevenue = $revenueRanking[0] ?? null;

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
                'average_performance_cost_excluding_black' => $averagePerformanceCostExcludingBlack,
                'black' => $blackCenterNet,
                'total_center_costs' => $totalCenterCosts,
                'net_center_margin' => $netCenterMargin,
                'top_by_performance_count' => $topByCount,
                'top_by_revenue' => $topByRevenue,
                'performance_count_ranking' => $performanceCountRanking,
                'revenue_ranking' => $revenueRanking,
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

        $expenseAllocations = $this->expenseAllocationsForRange($startDate, $endDate);

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

        foreach ($expenseAllocations as $allocation) {
            $key = sprintf('%04d-%02d', $allocation->competence_year, $allocation->competence_month);

            if (! $months->has($key)) {
                continue;
            }

            $row = $months->get($key);
            $type = $allocation->expenseRecord?->type?->value ?? $allocation->expenseRecord?->type;

            if ($type === 'fixed') {
                $row['fixed_costs'] += (float) $allocation->allocated_amount;
            } else {
                $row['variable_costs'] += (float) $allocation->allocated_amount;
            }

            $months->put($key, $row);
        }

        $trends = $months->map(function (array $row): array {
            $row['center_amount'] = round($row['center_amount'], 2);
            $row['professional_amount'] = round($row['professional_amount'], 2);
            $row['fixed_costs'] = round($row['fixed_costs'], 2);
            $row['variable_costs'] = round($row['variable_costs'], 2);
            $row['net_margin'] = round(($row['center_amount'] + $row['professional_amount']) - ($row['fixed_costs'] + $row['variable_costs']), 2);

            return $row;
        })->values()->all();

        return [
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'monthly_trends' => $trends,
            'professional_split' => $this->professionalSplit($performanceRecords),
            'expense_category_split' => $this->expenseCategorySplit($expenseAllocations),
        ];
    }

    private function professionalSplit(Collection $records): array
    {
        return $this->groupPerformanceRecordsByProfessional($records)
            ->map(function (Collection $group): array {
                $professional = $this->professionalSummary($group);

                return [
                    ...$professional,
                    'label' => $professional['professional_name'],
                    'total' => round((float) $group->sum('professional_amount'), 2),
                ];
            })
            ->sortBy([
                ['total', 'desc'],
                ['professional_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function performanceCountRanking(Collection $records): array
    {
        return $this->groupPerformanceRecordsByProfessional($records)
            ->map(function (Collection $group): array {
                $professional = $this->professionalSummary($group);

                return [
                    ...$professional,
                    'performances' => (int) $group->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                ];
            })
            ->sortBy([
                ['performances', 'desc'],
                ['professional_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function revenueRanking(Collection $records): array
    {
        return $this->groupPerformanceRecordsByProfessional($records)
            ->map(function (Collection $group): array {
                $professional = $this->professionalSummary($group);

                return [
                    ...$professional,
                    'revenue_total' => round((float) $group->sum('total_amount'), 2),
                ];
            })
            ->sortBy([
                ['revenue_total', 'desc'],
                ['professional_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function groupPerformanceRecordsByProfessional(Collection $records): Collection
    {
        return $records->groupBy(function (PerformanceRecord $record): string {
            if ($record->professional_id) {
                return 'id:'.$record->professional_id;
            }

            $snapshotName = trim((string) ($record->professional_name_snapshot ?: ''));

            return 'name:'.strtolower($snapshotName !== '' ? $snapshotName : 'Non specificato');
        });
    }

    private function professionalSummary(Collection $group): array
    {
        /** @var PerformanceRecord|null $firstRecord */
        $firstRecord = $group->first();

        return [
            'professional_id' => $firstRecord?->professional_id,
            'professional_name' => trim((string) ($firstRecord?->professional_name_snapshot ?: '')) ?: 'Non specificato',
        ];
    }

    private function expenseCategorySplit(Collection $allocations): array
    {
        return $allocations
            ->groupBy(fn (ExpenseRecordCompetence $allocation) => $allocation->expenseRecord?->category?->name ?: 'Non specificato')
            ->map(fn (Collection $group, string $name) => [
                'label' => $name ?: 'Non specificato',
                'total' => round((float) $group->sum('allocated_amount'), 2),
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

    private function expenseAllocationsForRange(string $startDate, string $endDate): Collection
    {
        [$monthStartDate, $monthEndDate] = $this->resolveCompetenceMonthBounds($startDate, $endDate);

        return ExpenseRecordCompetence::query()
            ->with('expenseRecord.category')
            ->whereDate('competence_date', '>=', $monthStartDate)
            ->whereDate('competence_date', '<=', $monthEndDate)
            ->get();
    }

    private function resolveCompetenceMonthBounds(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfMonth();
        $end = Carbon::parse($endDate)->startOfMonth();

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        return [$start->toDateString(), $end->toDateString()];
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
