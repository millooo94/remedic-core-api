<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PerformanceSplitMode;
use App\Enums\PerformanceSplitSubjectType;
use App\Models\ExpenseRecord;
use App\Models\ExpenseRecordCompetence;
use App\Models\PerformanceRecord;
use App\Models\PerformanceRecordSplit;
use App\Support\Media\PublicMediaUrl;
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

        $fixedExpenseAllocations = $this->expenseAllocationsForRange($startDate, $endDate, 'fixed');
        $previousFixedExpenseAllocations = $this->expenseAllocationsForRange($previousStartDate, $previousEndDate, 'fixed');
        $variableExpenseRecords = $this->expenseRecordsForRange($startDate, $endDate, 'variable');
        $previousVariableExpenseRecords = $this->expenseRecordsForRange($previousStartDate, $previousEndDate, 'variable');

        $fixedCosts = round((float) $fixedExpenseAllocations->sum('allocated_amount'), 2);
        $variableCosts = round((float) $variableExpenseRecords->sum('amount'), 2);
        $ordinaryFixedCosts = round((float) $fixedExpenseAllocations
            ->filter(fn (ExpenseRecordCompetence $allocation) => ($allocation->expenseRecord?->nature?->value ?? $allocation->expenseRecord?->nature ?? 'ordinary') === 'ordinary')
            ->sum('allocated_amount'), 2);
        $ordinaryVariableCosts = round((float) $variableExpenseRecords
            ->filter(fn (ExpenseRecord $expense) => ($expense->nature?->value ?? $expense->nature ?? 'ordinary') === 'ordinary')
            ->sum('amount'), 2);
        $specialFixedCosts = round($fixedCosts - $ordinaryFixedCosts, 2);
        $specialVariableCosts = round($variableCosts - $ordinaryVariableCosts, 2);
        $ordinaryCosts = round($ordinaryFixedCosts + $ordinaryVariableCosts, 2);
        $specialCosts = round($specialFixedCosts + $specialVariableCosts, 2);
        $centerTotal = round((float) $performanceRecords->sum('center_amount'), 2);
        $ordinaryPerformanceRecords = $performanceRecords->filter(fn (PerformanceRecord $record) => ! $this->isSpecialPerformance($record));
        $specialPerformanceRecords = $performanceRecords->filter(fn (PerformanceRecord $record) => $this->isSpecialPerformance($record));
        $ordinaryCenterTotal = round((float) $ordinaryPerformanceRecords->sum('center_amount'), 2);
        $specialCenterTotal = round((float) $specialPerformanceRecords->sum('center_amount'), 2);
        $ordinaryRevenueTotal = round((float) $ordinaryPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $specialRevenueTotal = round((float) $specialPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $professionalTotal = round((float) $performanceRecords->sum(fn (PerformanceRecord $record) => $this->dashboardProfessionalAmountForRecord($record)), 2);
        $revenueTotal = round((float) $performanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $cashPerformanceRecords = $performanceRecords->where('payment_method', PaymentMethod::Cash);
        $cashRevenueTotal = round((float) $cashPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $cashBlackRevenueTotal = round((float) $cashPerformanceRecords->where('is_black', true)->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $cashFatturatiRevenueTotal = round((float) $cashPerformanceRecords
            ->filter(fn (PerformanceRecord $record) => ! $record->is_black && ! $record->is_provvigione)
            ->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $cashProvvigioneRevenueTotal = round((float) $cashPerformanceRecords
            ->where('is_provvigione', true)
            ->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $cardRevenueTotal = round((float) $performanceRecords
            ->where('payment_method', PaymentMethod::Card)
            ->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $totalPerformances = (int) $performanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $promoPerformances = (int) $performanceRecords
            ->where('is_promo', true)
            ->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $blackPerformances = (int) $performanceRecords
            ->where('is_black', true)
            ->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $provvigionePerformances = (int) $performanceRecords
            ->where('is_provvigione', true)
            ->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $standardPerformances = (int) $performanceRecords
            ->filter(fn (PerformanceRecord $record) => ! $record->is_black && ! $record->is_promo && ! $record->is_provvigione)
            ->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $nonPromoPerformanceRecords = $performanceRecords->where('is_promo', false);
        $nonPromoRevenueTotal = round((float) $nonPromoPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $nonPromoCenterTotal = round((float) $nonPromoPerformanceRecords->sum('center_amount'), 2);
        $nonPromoPerformanceCount = (int) $nonPromoPerformanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $averagePerformanceCost = $nonPromoPerformanceCount > 0
            ? round($nonPromoRevenueTotal / $nonPromoPerformanceCount, 2)
            : 0.0;
        $averageCenterGainPerPerformance = $nonPromoPerformanceCount > 0
            ? round($nonPromoCenterTotal / $nonPromoPerformanceCount, 2)
            : 0.0;
        $nonBlackPerformanceRecords = $nonPromoPerformanceRecords->where('is_black', false);
        $nonBlackRevenueTotal = round((float) $nonBlackPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $nonBlackCenterTotal = round((float) $nonBlackPerformanceRecords->sum('center_amount'), 2);
        $nonBlackPerformanceCount = (int) $nonBlackPerformanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $averagePerformanceCostExcludingBlack = $nonBlackPerformanceCount > 0
            ? round($nonBlackRevenueTotal / $nonBlackPerformanceCount, 2)
            : 0.0;
        $averageCenterGainPerPerformanceExcludingBlack = $nonBlackPerformanceCount > 0
            ? round($nonBlackCenterTotal / $nonBlackPerformanceCount, 2)
            : 0.0;
        $blackCenterNet = round((float) $performanceRecords->where('is_black', true)->sum('center_amount'), 2);
        $totalCenterCosts = round($fixedCosts + $variableCosts, 2);
        $netCenterMargin = round($revenueTotal - $totalCenterCosts, 2);
        $ordinaryMargin = round($ordinaryRevenueTotal - $ordinaryCosts, 2);
        $specialMargin = round($specialRevenueTotal - $specialCosts, 2);

        $previousFixedCosts = round((float) $previousFixedExpenseAllocations->sum('allocated_amount'), 2);
        $previousVariableCosts = round((float) $previousVariableExpenseRecords->sum('amount'), 2);
        $previousCenterTotal = round((float) $previousPerformanceRecords->sum('center_amount'), 2);
        $previousProfessionalTotal = round((float) $previousPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->dashboardProfessionalAmountForRecord($record)), 2);
        $previousRevenueTotal = round((float) $previousPerformanceRecords->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2);
        $previousTotalPerformances = (int) $previousPerformanceRecords->sum(fn (PerformanceRecord $record) => (int) $record->quantity);
        $previousBlackCenterNet = round((float) $previousPerformanceRecords->where('is_black', true)->sum('center_amount'), 2);
        $previousTotalCenterCosts = round($previousFixedCosts + $previousVariableCosts, 2);
        $previousNetCenterMargin = round($previousRevenueTotal - $previousTotalCenterCosts, 2);

        $performanceCountRanking = $this->performanceCountRanking($performanceRecords);
        $revenueRanking = $this->revenueRanking($performanceRecords);
        $specializationRanking = $this->specializationRanking($performanceRecords);
        $serviceRanking = $this->serviceRanking($performanceRecords);
        $topByCount = $performanceCountRanking[0] ?? null;
        $topByRevenue = $revenueRanking[0] ?? null;
        $topBySpecialization = $specializationRanking[0] ?? null;
        $topByService = $serviceRanking[0] ?? null;

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
                'performance_type_breakdown' => [
                    'standard' => $standardPerformances,
                    'black' => $blackPerformances,
                    'promo' => $promoPerformances,
                    'provvigione' => $provvigionePerformances,
                ],
                'total_center_amount' => $centerTotal,
                'total_professional_amount' => $professionalTotal,
                'total_revenue_amount' => $revenueTotal,
                'revenue_payment_breakdown' => [
                    'cash' => $cashRevenueTotal,
                    'card' => $cardRevenueTotal,
                    'cash_breakdown' => [
                        'black' => $cashBlackRevenueTotal,
                        'fatturati' => $cashFatturatiRevenueTotal,
                        'provvigione' => $cashProvvigioneRevenueTotal,
                    ],
                ],
                'total_fixed_costs' => $fixedCosts,
                'total_variable_costs' => $variableCosts,
                'cost_breakdown' => [
                    'total' => ['ordinary' => $ordinaryCosts, 'special' => $specialCosts, 'total' => $totalCenterCosts],
                    'fixed' => ['ordinary' => $ordinaryFixedCosts, 'special' => $specialFixedCosts, 'total' => $fixedCosts],
                    'variable' => ['ordinary' => $ordinaryVariableCosts, 'special' => $specialVariableCosts, 'total' => $variableCosts],
                ],
                'center_share_breakdown' => [
                    'ordinary' => $ordinaryCenterTotal,
                    'special' => $specialCenterTotal,
                    'total' => $centerTotal,
                ],
                'net_margin_breakdown' => [
                    'ordinary' => ['revenue' => $ordinaryRevenueTotal, 'costs' => $ordinaryCosts, 'margin' => $ordinaryMargin],
                    'special' => ['revenue' => $specialRevenueTotal, 'costs' => $specialCosts, 'margin' => $specialMargin],
                    'total' => ['revenue' => $revenueTotal, 'costs' => $totalCenterCosts, 'margin' => $netCenterMargin],
                ],
                'average_performance_cost' => $averagePerformanceCost,
                'average_performance_cost_excluding_black' => $averagePerformanceCostExcludingBlack,
                'average_center_gain_performance' => $averageCenterGainPerPerformance,
                'average_center_gain_performance_excluding_black' => $averageCenterGainPerPerformanceExcludingBlack,
                'black' => $blackCenterNet,
                'total_center_costs' => $totalCenterCosts,
                'net_center_margin' => $netCenterMargin,
                'professional_amount_breakdown' => $this->professionalAmountBreakdown($performanceRecords),
                'provvigione_collection_breakdown' => $this->provvigioneCollectionBreakdown($performanceRecords),
                'top_by_performance_count' => $topByCount,
                'top_by_revenue' => $topByRevenue,
                'top_by_specialization' => $topBySpecialization,
                'top_by_service' => $topByService,
                'performance_count_ranking' => $performanceCountRanking,
                'revenue_ranking' => $revenueRanking,
                'specialization_ranking' => $specializationRanking,
                'service_ranking' => $serviceRanking,
                'comparisons' => [
                    'total_performances' => $this->buildComparison($totalPerformances, $previousTotalPerformances, 0),
                    'total_revenue_amount' => $this->buildComparison($revenueTotal, $previousRevenueTotal),
                    'total_center_amount' => $this->buildComparison($centerTotal, $previousCenterTotal),
                    'total_professional_amount' => $this->buildComparison($professionalTotal, $previousProfessionalTotal),
                    'total_fixed_costs' => $this->buildComparison($fixedCosts, $previousFixedCosts),
                    'total_variable_costs' => $this->buildComparison($variableCosts, $previousVariableCosts),
                    'total_center_costs' => $this->buildComparison($totalCenterCosts, $previousTotalCenterCosts),
                    'net_center_margin' => $this->buildComparison($netCenterMargin, $previousNetCenterMargin),
                    'average_performance_cost' => $this->buildComparison(
                        $averagePerformanceCost,
                        $this->averageForComparison(
                            round((float) $previousPerformanceRecords
                                ->where('is_promo', false)
                                ->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2),
                            (int) $previousPerformanceRecords
                                ->where('is_promo', false)
                                ->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                        ),
                    ),
                    'black' => $this->buildComparison($blackCenterNet, $previousBlackCenterNet),
                ],
            ],
        ];
    }

    public function monthlyTrends(array $filters): array
    {
        [$startDate, $endDate] = $this->resolveRange($filters);

        $performanceRecords = PerformanceRecord::query()
            ->with('splits')
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
                'recognized_revenue' => 0.0,
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
            $row['professional_amount'] += $this->dashboardProfessionalAmountForRecord($record);
            $row['recognized_revenue'] += $this->recognizedRevenueForRecord($record);
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
            $row['net_margin'] = round(($row['recognized_revenue'] ?? 0) - ($row['fixed_costs'] + $row['variable_costs']), 2);
            unset($row['recognized_revenue']);

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
        return $this->professionalAllocationGroups($records)
            ->map(fn (Collection $group) => [
                'professional_id' => $group->first()['professional_id'],
                'professional_name' => $group->first()['professional_name'],
                'label' => $group->first()['professional_name'],
                'total' => round((float) $group->sum('amount'), 2),
            ])
            ->sortBy([
                ['total', 'desc'],
                ['professional_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function performanceCountRanking(Collection $records): array
    {
        return $this->professionalAllocationGroups($records)
            ->map(fn (Collection $group) => [
                'professional_id' => $group->first()['professional_id'],
                'professional_name' => $group->first()['professional_name'],
                'performances' => (int) $group->sum('performances'),
                'promo_performances' => (int) $group->sum('promo_performances'),
            ])
            ->sortBy([
                ['performances', 'desc'],
                ['professional_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function revenueRanking(Collection $records): array
    {
        return $this->professionalAllocationGroups($records)
            ->map(fn (Collection $group) => [
                'professional_id' => $group->first()['professional_id'],
                'professional_name' => $group->first()['professional_name'],
                'revenue_total' => round((float) $group->sum('revenue_total'), 2),
            ])
            ->sortBy([
                ['revenue_total', 'desc'],
                ['professional_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function professionalAllocationGroups(Collection $records): Collection
    {
        return $this->professionalAllocations($records)
            ->groupBy(function (array $allocation): string {
                if (! empty($allocation['professional_id'])) {
                    return 'id:'.$allocation['professional_id'];
                }

                return 'name:'.strtolower(trim((string) $allocation['professional_name']) ?: 'Non specificato');
            });
    }

    private function professionalAmountBreakdown(Collection $records): array
    {
        $allocations = $this->professionalAllocations($records);
        $total = round((float) $allocations->sum('amount'), 2);
        $liquidated = round((float) $allocations
            ->filter(fn (array $allocation) => $allocation['payment_status'] === PaymentStatus::Pagata->value)
            ->sum('amount'), 2);
        $toLiquidate = round($total - $liquidated, 2);

        return [
            'total' => $total,
            'liquidated' => $liquidated,
            'to_liquidate' => $toLiquidate,
            'liquidated_percent' => $total > 0 ? round(($liquidated / $total) * 100, 2) : 0.0,
            'to_liquidate_percent' => $total > 0 ? round(($toLiquidate / $total) * 100, 2) : 0.0,
            'fiscal_split' => [
                'total' => [
                    'white' => $this->sumProfessionalAllocations($allocations, null, false),
                    'black' => $this->sumProfessionalAllocations($allocations, null, true),
                    'provvigione' => $this->sumProfessionalAllocations($allocations, null, true, true),
                ],
                'liquidated' => [
                    'white' => $this->sumProfessionalAllocations($allocations, PaymentStatus::Pagata->value, false),
                    'black' => $this->sumProfessionalAllocations($allocations, PaymentStatus::Pagata->value, true),
                    'provvigione' => $this->sumProfessionalAllocations($allocations, PaymentStatus::Pagata->value, true, true),
                ],
                'to_liquidate' => [
                    'white' => $this->sumProfessionalAllocations($allocations, PaymentStatus::DaPagare->value, false),
                    'black' => $this->sumProfessionalAllocations($allocations, PaymentStatus::DaPagare->value, true),
                    'provvigione' => $this->sumProfessionalAllocations($allocations, PaymentStatus::DaPagare->value, true, true),
                ],
            ],
        ];
    }

    private function provvigioneCollectionBreakdown(Collection $records): array
    {
        $provvigioneRecords = $records->filter(fn (PerformanceRecord $record) => (bool) $record->is_provvigione);
        $total = round((float) $provvigioneRecords->sum('center_amount'), 2);
        $collected = round((float) $provvigioneRecords
            ->filter(fn (PerformanceRecord $record) => ($record->payment_status?->value ?? $record->payment_status ?? PaymentStatus::DaPagare->value) === PaymentStatus::Pagata->value)
            ->sum('center_amount'), 2);
        $toCollect = round($total - $collected, 2);

        return [
            'total' => $total,
            'collected' => $collected,
            'to_collect' => $toCollect,
            'collected_percent' => $total > 0 ? round(($collected / $total) * 100, 2) : 0.0,
            'to_collect_percent' => $total > 0 ? round(($toCollect / $total) * 100, 2) : 0.0,
        ];
    }

    private function specializationRanking(Collection $records): array
    {
        return $records
            ->groupBy(fn (PerformanceRecord $record) => $this->normalizedRankingLabel($record->category_name_snapshot))
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'icon_url' => $this->specializationIconUrlForRanking($group, $label),
                'performances' => (int) $group->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                'promo_performances' => (int) $group
                    ->where('is_promo', true)
                    ->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                'revenue_total' => round((float) $group->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2),
            ])
            ->sortBy([
                ['performances', 'desc'],
                ['revenue_total', 'desc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function serviceRanking(Collection $records): array
    {
        return $records
            ->groupBy(fn (PerformanceRecord $record) => $this->normalizedRankingLabel($record->service_name_snapshot))
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'image_url' => $this->serviceImageUrlForRanking($group),
                'performances' => (int) $group->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                'promo_performances' => (int) $group
                    ->where('is_promo', true)
                    ->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                'revenue_total' => round((float) $group->sum(fn (PerformanceRecord $record) => $this->recognizedRevenueForRecord($record)), 2),
            ])
            ->sortBy([
                ['performances', 'desc'],
                ['revenue_total', 'desc'],
                ['label', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function professionalAllocations(Collection $records): Collection
    {
        $rows = collect();

        /** @var PerformanceRecord $record */
        foreach ($records as $record) {
            $splitMode = $record->split_mode?->value ?? $record->split_mode;
            if ($splitMode === PerformanceSplitMode::Advanced->value) {
                /** @var Collection<int, PerformanceRecordSplit> $splits */
                $splits = $record->relationLoaded('splits')
                    ? $record->splits
                    : $record->splits()->with('professional')->get();

                $professionalRows = $splits
                    ->filter(fn (PerformanceRecordSplit $split) => ($split->subject_type?->value ?? $split->subject_type) === PerformanceSplitSubjectType::Professional->value)
                    ->groupBy(fn (PerformanceRecordSplit $split) => $split->professional_id ?: ('name:'.strtolower(trim((string) $split->professional_name_snapshot))));

                foreach ($professionalRows as $group) {
                    /** @var PerformanceRecordSplit|null $first */
                    $first = $group->first();
                    $amount = round((float) $group->sum('amount'), 2);

                    $rows->push([
                        'professional_id' => $first?->professional_id,
                        'professional_name' => trim((string) ($first?->professional_name_snapshot ?: $first?->professional?->full_name ?: 'Non specificato')) ?: 'Non specificato',
                        'amount' => $record->is_provvigione ? 0.0 : $amount,
                        'is_black' => (bool) $record->is_black,
                        'is_provvigione' => (bool) $record->is_provvigione,
                        'performances' => (int) $record->quantity,
                        'promo_performances' => $record->is_promo ? (int) $record->quantity : 0,
                        'revenue_total' => $this->recognizedRevenueForRecord($record),
                        'payment_status' => $record->payment_status?->value ?? $record->payment_status ?? PaymentStatus::DaPagare->value,
                    ]);
                }

                continue;
            }

            $rows->push([
                'professional_id' => $record->professional_id,
                'professional_name' => trim((string) ($record->professional_name_snapshot ?: '')) ?: 'Non specificato',
                'amount' => $this->dashboardProfessionalAmountForRecord($record),
                'is_black' => (bool) $record->is_black,
                'is_provvigione' => (bool) $record->is_provvigione,
                'performances' => (int) $record->quantity,
                'promo_performances' => $record->is_promo ? (int) $record->quantity : 0,
                'revenue_total' => $this->recognizedRevenueForRecord($record),
                'payment_status' => $record->payment_status?->value ?? $record->payment_status ?? PaymentStatus::DaPagare->value,
            ]);
        }

        return $rows;
    }

    private function sumProfessionalAllocations(Collection $allocations, ?string $paymentStatus, bool $isBlack, bool $isProvvigione = false): float
    {
        return round((float) $allocations
            ->filter(function (array $allocation) use ($paymentStatus, $isBlack, $isProvvigione): bool {
                if ((bool) ($allocation['is_black'] ?? false) !== $isBlack) {
                    return false;
                }

                if ((bool) ($allocation['is_provvigione'] ?? false) !== $isProvvigione) {
                    return false;
                }

                if ($paymentStatus === null) {
                    return true;
                }

                return ($allocation['payment_status'] ?? PaymentStatus::DaPagare->value) === $paymentStatus;
            })
            ->sum('amount'), 2);
    }

    private function recognizedRevenueForRecord(PerformanceRecord $record): float
    {
        return round(
            (float) ($record->is_provvigione ? $record->center_amount : $record->total_amount),
            2,
        );
    }

    private function isSpecialPerformance(PerformanceRecord $record): bool
    {
        return (bool) $record->is_black || (bool) $record->is_provvigione;
    }

    private function specializationIconUrlForRanking(Collection $records, string $label): ?string
    {
        $specialization = $records
            ->flatMap(fn (PerformanceRecord $record) => $record->service?->specializations ?? collect())
            ->first(fn ($candidate) => $candidate->name === $label);

        return PublicMediaUrl::fromPublicDisk($specialization?->icon_path, request());
    }

    private function serviceImageUrlForRanking(Collection $records): ?string
    {
        $service = $records->pluck('service')->filter()->first();

        return PublicMediaUrl::fromPublicDisk($service?->featured_image_path, request());
    }

    private function dashboardProfessionalAmountForRecord(PerformanceRecord $record): float
    {
        return $record->is_provvigione
            ? 0.0
            : round((float) $record->professional_amount, 2);
    }

    private function normalizedRankingLabel(?string $value): string
    {
        $label = trim((string) $value);

        return $label !== '' ? $label : 'Non specificato';
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
            ->with(['splits', 'service.specializations'])
            ->whereDate('performed_at', '>=', $startDate)
            ->whereDate('performed_at', '<=', $endDate)
            ->get();
    }

    private function expenseAllocationsForRange(string $startDate, string $endDate, ?string $type = null): Collection
    {
        [$monthStartDate, $monthEndDate] = $this->resolveCompetenceMonthBounds($startDate, $endDate);

        $query = ExpenseRecordCompetence::query()
            ->with('expenseRecord.category')
            ->whereDate('competence_date', '>=', $monthStartDate)
            ->whereDate('competence_date', '<=', $monthEndDate);

        if ($type !== null) {
            $query->whereHas('expenseRecord', fn ($expenseQuery) => $expenseQuery->where('type', $type));
        }

        return $query->get();
    }

    private function expenseRecordsForRange(string $startDate, string $endDate, ?string $type = null): Collection
    {
        $query = ExpenseRecord::query()
            ->whereDate('expense_date', '>=', $startDate)
            ->whereDate('expense_date', '<=', $endDate);

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get();
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
