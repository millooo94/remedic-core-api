<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PerformanceSplitMode;
use App\Enums\PerformanceSplitSubjectType;
use App\Exports\PerformanceRecordsExportWorkbook;
use App\Models\Patient;
use App\Models\PerformanceRecord;
use App\Models\PerformanceRecordSplit;
use App\Models\Professional;
use App\Models\Service;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PerformanceRecordExportService
{
    public function __construct(
        private readonly PerformanceRecordService $performanceRecordService,
    ) {
    }

    public function build(array $filters = []): array
    {
        $records = $this->performanceRecordService->baseQuery($filters)->get();
        $professionalAllocations = $this->buildProfessionalAllocations($records);
        $visibleFiscalTypes = $this->resolveVisibleFiscalTypes($filters, $records, $professionalAllocations);
        $period = $this->resolvePeriod($filters);
        $totals = $this->buildTotals($records, $professionalAllocations, $visibleFiscalTypes);
        $singleProfessional = $this->resolveSingleProfessional($records, $filters);
        $documentTitle = $singleProfessional
            ? sprintf('Prospetto prestazioni filtrate - %s', $singleProfessional->full_name)
            : 'Export prestazioni filtrate';

        $fileStem = sprintf(
            '%s-%s-%s',
            $singleProfessional ? Str::slug($singleProfessional->full_name) : 'prestazioni-filtrate',
            Carbon::parse($period['start_date'])->format('d-m-Y'),
            Carbon::parse($period['end_date'])->format('d-m-Y'),
        );

        return [
            'document_title' => $documentTitle,
            'generated_at' => now()->toIso8601String(),
            'period' => $period,
            'applied_filters' => $this->appliedFilters($filters),
            'visible_fiscal_types' => $visibleFiscalTypes,
            'fiscal_breakdown' => $this->buildOverallFiscalBreakdown($totals, $visibleFiscalTypes),
            'professional_subtotals' => $this->buildProfessionalSubtotals($professionalAllocations, $visibleFiscalTypes),
            'records' => $records->map(fn (PerformanceRecord $record) => [
                'performed_at' => $record->performed_at?->toDateString(),
                'professional_name' => $record->professional_name_snapshot,
                'area_name' => $record->category_name_snapshot,
                'service_name' => $record->service_name_snapshot,
                'quantity' => (int) $record->quantity,
                'total_amount' => (float) $record->total_amount,
                'professional_amount' => $this->payableProfessionalAmountForRecord($record),
                'payment_status' => $this->paymentStatusValue($record),
                'payment_status_label' => $this->paymentStatusLabel($record),
                'is_invoiced' => (bool) $record->is_invoiced,
                'invoicing_status' => $record->is_invoiced ? 'Fatturata' : 'Da fatturare',
                'is_black' => (bool) $record->is_black,
                'is_provvigione' => (bool) $record->is_provvigione,
                'fiscal_type_label' => $this->fiscalTypeLabel($this->fiscalTypeForRecord($record)),
                'notes' => $this->exportNotesFor($record),
            ])->all(),
            'totals' => $totals,
            'message' => $this->buildMessage($totals, $visibleFiscalTypes),
            'file_name_pdf' => $fileStem.'.pdf',
            'file_name_excel' => $fileStem.'.xlsx',
        ];
    }

    public function downloadPdf(array $filters = []): Response
    {
        $exportData = $this->build($filters);
        $pdf = Pdf::loadView('pdf.performance-records-export', [
            'exportData' => $exportData,
            'logoSvg' => $this->logoSvg(),
        ]);

        return $pdf->download($exportData['file_name_pdf']);
    }

    public function downloadExcel(array $filters = []): BinaryFileResponse
    {
        $exportData = $this->build($filters);

        return Excel::download(
            new PerformanceRecordsExportWorkbook($exportData),
            $exportData['file_name_excel'],
        );
    }

    private function buildTotals(Collection $records, Collection $professionalAllocations, array $visibleFiscalTypes): array
    {
        $whiteCounts = $this->buildRecordTypeTotals($records, 'white');
        $blackCounts = $this->buildRecordTypeTotals($records, 'black');
        $whiteAmount = round((float) $professionalAllocations->where('fiscal_type', 'white')->sum('professional_amount'), 2);
        $blackAmount = round((float) $professionalAllocations->where('fiscal_type', 'black')->sum('professional_amount'), 2);
        $provvigioneCounts = $this->buildRecordTypeTotals($records, 'provvigione');
        $provvigioneAmount = round((float) $professionalAllocations->where('fiscal_type', 'provvigione')->sum('professional_amount'), 2);
        $provvigioneCenterAmount = round((float) $records
            ->filter(fn (PerformanceRecord $record) => $this->fiscalTypeForRecord($record) === 'provvigione')
            ->sum(fn (PerformanceRecord $record) => (float) $record->center_amount), 2);

        return [
            'records_count' => $records->count(),
            'performance_count' => (int) $records->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
            'professional_count' => $professionalAllocations->pluck('professional_key')->filter()->unique()->count(),
            'liquidated_count' => (int) $records
                ->filter(fn (PerformanceRecord $record) => $this->paymentStatusValue($record) === PaymentStatus::Pagata->value)
                ->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
            'invoiced_count' => (int) $records
                ->filter(fn (PerformanceRecord $record) => (bool) $record->is_invoiced)
                ->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
            'total_amount' => round((float) $records->sum(fn (PerformanceRecord $record) => (float) $record->total_amount), 2),
            'professional_amount' => round((float) $professionalAllocations->sum('professional_amount'), 2),
            'visible_fiscal_types' => $visibleFiscalTypes,
            'white' => [
                ...$whiteCounts,
                'professional_amount' => $whiteAmount,
            ],
            'black' => [
                ...$blackCounts,
                'professional_amount' => $blackAmount,
            ],
            'provvigione' => [
                ...$provvigioneCounts,
                'professional_amount' => $provvigioneAmount,
                'recognized_center_amount' => $provvigioneCenterAmount,
            ],
            'total' => [
                'records_count' => $records->count(),
                'performance_count' => (int) $records->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
                'professional_amount' => round((float) $professionalAllocations->sum('professional_amount'), 2),
            ],
        ];
    }

    private function buildProfessionalSubtotals(Collection $professionalAllocations, array $visibleFiscalTypes): array
    {
        return $professionalAllocations
            ->groupBy(fn (array $allocation) => $allocation['professional_key'])
            ->map(function (Collection $group) use ($visibleFiscalTypes): array {
                $professionalName = (string) ($group->first()['professional_name'] ?? 'Professionista');
                $white = $this->summarizeAllocationRows($group->where('fiscal_type', 'white'));
                $black = $this->summarizeAllocationRows($group->where('fiscal_type', 'black'));
                $provvigione = $this->summarizeAllocationRows($group->where('fiscal_type', 'provvigione'));
                $total = $this->summarizeAllocationRows($group);

                return [
                    'professional_name' => $professionalName,
                    'records_count' => $total['records_count'],
                    'performance_count' => $total['performance_count'],
                    'professional_amount' => $total['professional_amount'],
                    'white' => $white,
                    'black' => $black,
                    'provvigione' => $provvigione,
                    'total' => $total,
                    'fiscal_breakdown' => $this->buildFiscalBreakdownRowsFromSummaries([
                        'white' => $white,
                        'black' => $black,
                        'provvigione' => $provvigione,
                        'total' => $total,
                    ], $visibleFiscalTypes),
                ];
            })
            ->sortBy('professional_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function buildProfessionalAllocations(Collection $records): Collection
    {
        return $records
            ->flatMap(fn (PerformanceRecord $record) => $this->professionalAllocationsForRecord($record))
            ->values();
    }

    private function professionalAllocationsForRecord(PerformanceRecord $record): Collection
    {
        $fiscalType = $this->fiscalTypeForRecord($record);
        $performanceCount = (int) $record->quantity;
        $splitMode = $record->split_mode?->value ?? $record->split_mode;

        if ($splitMode === PerformanceSplitMode::Advanced->value) {
            /** @var Collection<int, PerformanceRecordSplit> $splits */
            $splits = $record->relationLoaded('splits') ? $record->splits : $record->splits()->with('professional')->get();

            return $splits
                ->filter(fn (PerformanceRecordSplit $split) => ($split->subject_type?->value ?? $split->subject_type) === PerformanceSplitSubjectType::Professional->value)
                ->groupBy(function (PerformanceRecordSplit $split): string {
                    if ($split->professional_id) {
                        return 'id:'.$split->professional_id;
                    }

                    return 'name:'.mb_strtolower(trim((string) ($split->professional_name_snapshot ?? 'professionista')));
                })
                ->map(function (Collection $group) use ($fiscalType, $performanceCount): array {
                    /** @var PerformanceRecordSplit $first */
                    $first = $group->first();
                    $professionalName = trim((string) ($first->professional_name_snapshot ?? ''));
                    if ($professionalName === '') {
                        $professionalName = $first->professional?->full_name ?? 'Professionista';
                    }

                    return [
                        'professional_key' => $first->professional_id ? 'id:'.$first->professional_id : 'name:'.mb_strtolower($professionalName),
                        'professional_id' => $first->professional_id ? (int) $first->professional_id : null,
                        'professional_name' => $professionalName,
                        'records_count' => 1,
                        'performance_count' => $performanceCount,
                        'professional_amount' => round((float) $group->sum('amount'), 2),
                        'fiscal_type' => $fiscalType,
                    ];
                })
                ->filter(fn (array $row) => $row['professional_amount'] > 0)
                ->values();
        }

        $amount = $this->payableProfessionalAmountForRecord($record);
        if ($amount <= 0 && ! $record->is_provvigione) {
            return collect();
        }

        $professionalName = trim((string) ($record->professional_name_snapshot ?? '')) ?: 'Professionista';

        return collect([[
            'professional_key' => $record->professional_id ? 'id:'.$record->professional_id : 'name:'.mb_strtolower($professionalName),
            'professional_id' => $record->professional_id ? (int) $record->professional_id : null,
            'professional_name' => $professionalName,
            'records_count' => 1,
            'performance_count' => $performanceCount,
            'professional_amount' => $amount,
            'fiscal_type' => $fiscalType,
        ]]);
    }

    private function summarizeAllocationRows(Collection $rows): array
    {
        return [
            'records_count' => (int) $rows->sum('records_count'),
            'performance_count' => (int) $rows->sum('performance_count'),
            'professional_amount' => round((float) $rows->sum('professional_amount'), 2),
        ];
    }

    private function buildOverallFiscalBreakdown(array $totals, array $visibleFiscalTypes): array
    {
        return $this->buildFiscalBreakdownRowsFromSummaries([
            'white' => $totals['white'],
            'black' => $totals['black'],
            'provvigione' => $totals['provvigione'],
            'total' => $totals['total'],
        ], $visibleFiscalTypes);
    }

    private function buildFiscalBreakdownRowsFromSummaries(array $summaries, array $visibleFiscalTypes): array
    {
        $rows = [];

        foreach ($visibleFiscalTypes as $type) {
            $summary = $summaries[$type] ?? ['records_count' => 0, 'performance_count' => 0, 'professional_amount' => 0.0];
            $rows[] = [
                'type' => $type,
                'label' => $this->fiscalTypeLabel($type),
                'records_count' => (int) ($summary['records_count'] ?? 0),
                'performance_count' => (int) ($summary['performance_count'] ?? 0),
                'professional_amount' => round((float) ($summary['professional_amount'] ?? 0), 2),
            ];
        }

        $total = $summaries['total'] ?? ['records_count' => 0, 'performance_count' => 0, 'professional_amount' => 0.0];
        $rows[] = [
            'type' => 'total',
            'label' => 'Totale',
            'records_count' => (int) ($total['records_count'] ?? 0),
            'performance_count' => (int) ($total['performance_count'] ?? 0),
            'professional_amount' => round((float) ($total['professional_amount'] ?? 0), 2),
        ];

        return $rows;
    }

    private function buildRecordTypeTotals(Collection $records, string $type): array
    {
        $filtered = $records->filter(fn (PerformanceRecord $record) => $this->fiscalTypeForRecord($record) === $type);

        return [
            'records_count' => $filtered->count(),
            'performance_count' => (int) $filtered->sum(fn (PerformanceRecord $record) => (int) $record->quantity),
        ];
    }

    private function resolveSingleProfessional(Collection $records, array $filters): ?Professional
    {
        if (! isset($filters['professional_id'])) {
            return null;
        }

        return Professional::query()->find($filters['professional_id']);
    }

    private function appliedFilters(array $filters): array
    {
        $labels = [];

        if (! empty($filters['q'])) {
            $labels[] = 'Ricerca: '.$filters['q'];
        }

        if (! empty($filters['patient_id'])) {
            $patient = Patient::query()->find($filters['patient_id']);
            if ($patient) {
                $labels[] = 'Paziente: '.$patient->full_name;
            }
        }

        if (! empty($filters['professional_id'])) {
            $professional = Professional::query()->find($filters['professional_id']);
            if ($professional) {
                $labels[] = 'Professionista: '.$professional->full_name;
            }
        }

        if (! empty($filters['area_name'])) {
            $labels[] = 'Area: '.$filters['area_name'];
        }

        if (! empty($filters['service_id'])) {
            $service = Service::query()->find($filters['service_id']);
            if ($service) {
                $labels[] = 'Prestazione: '.$service->display_name;
            }
        }

        if (($filters['invoice_filter'] ?? 'all') !== 'all') {
            $labels[] = 'Fatturazione: '.match ($filters['invoice_filter']) {
                'invoiced' => 'Fatturate',
                'not_invoiced' => 'Non fatturate',
                default => 'Tutte',
            };
        }

        if (($filters['liquidation_filter'] ?? 'all') !== 'all') {
            $labels[] = 'Liquidazione: '.match ($filters['liquidation_filter']) {
                'liquidated' => 'Liquidate',
                'not_liquidated' => 'Non liquidate',
                default => 'Tutte',
            };
        }

        if (($filters['fiscal_filter'] ?? 'all') !== 'all') {
            $labels[] = 'Tipo: '.match ($filters['fiscal_filter']) {
                'white' => 'Ordinarie',
                'black' => 'Speciali',
                'provvigione' => 'Provvigione',
                default => 'Tutte',
            };
        }

        if (! empty($filters['only_unreconciled'])) {
            $labels[] = 'Solo prestazioni senza paziente riconciliato';
        }

        return $labels;
    }

    private function resolvePeriod(array $filters): array
    {
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $start = ! empty($filters['date_from'])
                ? Carbon::parse((string) $filters['date_from'])->startOfDay()
                : now()->startOfMonth();
            $end = ! empty($filters['date_to'])
                ? Carbon::parse((string) $filters['date_to'])->endOfDay()
                : now()->endOfDay();
        } elseif (! empty($filters['month']) && ! empty($filters['year'])) {
            $start = Carbon::create((int) $filters['year'], (int) $filters['month'], 1)->startOfDay();
            $end = $start->copy()->endOfMonth()->endOfDay();
        } else {
            $start = now()->startOfMonth();
            $end = now()->endOfDay();
        }

        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [
            'label' => sprintf('%s - %s', $start->format('d/m/Y'), $end->format('d/m/Y')),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
    }

    private function exportNotesFor(PerformanceRecord $record): ?string
    {
        $parts = [];

        if ($record->is_promo) {
            $parts[] = 'Promo';
        }

        if ($record->is_provvigione) {
            $parts[] = 'Provvigione Remedic';
        }

        $note = trim((string) ($record->notes ?? ''));
        if ($note !== '') {
            $parts[] = $note;
        }

        return $parts !== [] ? implode(' | ', $parts) : null;
    }

    private function paymentStatusValue(PerformanceRecord $record): string
    {
        return $record->payment_status?->value ?? $record->payment_status ?? PaymentStatus::DaPagare->value;
    }

    private function paymentStatusLabel(PerformanceRecord $record): string
    {
        if ($record->is_provvigione) {
            return $this->paymentStatusValue($record) === PaymentStatus::Pagata->value
                ? 'Incassata da Remedic'
                : 'Da incassare a Remedic';
        }

        return $this->paymentStatusValue($record) === PaymentStatus::Pagata->value
            ? 'Liquidata'
            : 'Da liquidare';
    }

    private function resolveVisibleFiscalTypes(array $filters, Collection $records, Collection $professionalAllocations): array
    {
        $selected = match ($filters['fiscal_filter'] ?? 'all') {
            'white' => ['white'],
            'black' => ['black'],
            'provvigione' => ['provvigione'],
            default => ['white', 'black', 'provvigione'],
        };

        if (($filters['fiscal_filter'] ?? 'all') !== 'all') {
            return $selected;
        }

        return array_values(array_filter($selected, function (string $type) use ($records, $professionalAllocations): bool {
            $hasRecords = $records->contains(fn (PerformanceRecord $record) => $this->fiscalTypeForRecord($record) === $type);
            $hasAmount = (float) $professionalAllocations
                ->where('fiscal_type', $type)
                ->sum('professional_amount') > 0;

            return $hasRecords || $hasAmount;
        }));
    }

    private function fiscalTypeForRecord(PerformanceRecord $record): string
    {
        if ($record->is_provvigione) {
            return 'provvigione';
        }

        return $record->is_black ? 'black' : 'white';
    }

    private function fiscalTypeLabel(string $type): string
    {
        return match ($type) {
            'white' => 'Ordinarie',
            'black' => 'Speciali',
            'provvigione' => 'Provvigione',
            default => 'Totale',
        };
    }

    private function buildMessage(array $totals, array $visibleFiscalTypes): string
    {
        $provvigioneCommissionTotal = round((float) ($totals['provvigione']['recognized_center_amount'] ?? 0), 2);

        if ($visibleFiscalTypes === ['white']) {
            return sprintf(
                'Totale quota professionista ordinaria filtrata: %s su %d prestazioni filtrate.',
                $this->formatEuro((float) $totals['white']['professional_amount']),
                (int) $totals['white']['performance_count'],
            );
        }

        if ($visibleFiscalTypes === ['black']) {
            return sprintf(
                'Totale quota professionista speciale filtrata: %s su %d prestazioni filtrate.',
                $this->formatEuro((float) $totals['black']['professional_amount']),
                (int) $totals['black']['performance_count'],
            );
        }

        if ($visibleFiscalTypes === ['provvigione']) {
            return sprintf(
                'Totale provvigioni Remedic filtrate: %s su %d prestazioni filtrate.',
                $this->formatEuro($provvigioneCommissionTotal),
                (int) $totals['provvigione']['performance_count'],
            );
        }

        return sprintf(
            'Totale quota professionista filtrata: %s (Ordinarie %s, Speciali %s) + Provvigioni Remedic %s su %d prestazioni filtrate.',
            $this->formatEuro((float) $totals['total']['professional_amount']),
            $this->formatEuro((float) $totals['white']['professional_amount']),
            $this->formatEuro((float) $totals['black']['professional_amount']),
            $this->formatEuro($provvigioneCommissionTotal),
            (int) $totals['total']['performance_count'],
        );
    }

    private function payableProfessionalAmountForRecord(PerformanceRecord $record): float
    {
        return $record->is_provvigione
            ? 0.0
            : round((float) $record->professional_amount, 2);
    }

    private function formatEuro(float $amount): string
    {
        return "\u{20AC} ".number_format($amount, 2, ',', '.');
    }

    private function logoSvg(): string
    {
        $logoPath = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'images'.DIRECTORY_SEPARATOR.'logo.svg';

        if (! is_file($logoPath)) {
            return '';
        }

        $svg = file_get_contents($logoPath) ?: '';
        $svg = preg_replace('/<\?xml.*?\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;

        return trim($svg);
    }
}
