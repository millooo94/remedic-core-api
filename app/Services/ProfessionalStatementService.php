<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PerformanceSplitMode;
use App\Enums\PerformanceSplitSubjectType;
use App\Exports\ProfessionalStatementWorkbookExport;
use App\Models\PerformanceRecord;
use App\Models\PerformanceRecordSplit;
use App\Models\Professional;
use App\Support\Professionals\IbanFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfessionalStatementService
{
    public function build(Professional $professional, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->resolvePeriod($startDate, $endDate);

        $records = PerformanceRecord::query()
            ->with('splits')
            ->whereDate('performed_at', '>=', $period['start_date'])
            ->whereDate('performed_at', '<=', $period['end_date'])
            ->where('is_invoiced', false)
            ->where('is_provvigione', false)
            ->where(function ($query) use ($professional): void {
                $query
                    ->where(function ($standard) use ($professional): void {
                        $standard
                            ->where(function ($modeQuery): void {
                                $modeQuery
                                    ->whereNull('split_mode')
                                    ->orWhere('split_mode', PerformanceSplitMode::Standard->value);
                            })
                            ->where('professional_id', $professional->id);
                    })
                    ->orWhere(function ($advanced) use ($professional): void {
                        $advanced
                            ->where('split_mode', PerformanceSplitMode::Advanced->value)
                            ->whereHas('splits', function ($splitQuery) use ($professional): void {
                                $splitQuery
                                    ->where('subject_type', PerformanceSplitSubjectType::Professional->value)
                                    ->where('professional_id', $professional->id);
                            });
                    });
            })
            ->orderBy('performed_at')
            ->get();

        $recordsWithAmounts = $records
            ->map(fn (PerformanceRecord $record): array => [
                'record' => $record,
                'allocated_amount' => $this->allocatedAmountForProfessional($record, $professional->id),
            ])
            ->filter(fn (array $row) => $row['allocated_amount'] > 0)
            ->values();

        $alreadyLiquidatedRecords = $recordsWithAmounts
            ->filter(fn (array $row) => $this->paymentStatusValue($row['record']) === PaymentStatus::Pagata->value)
            ->values();

        $performanceCount = (int) $recordsWithAmounts->sum(fn (array $row) => (int) $row['record']->quantity);
        $alreadyLiquidatedCount = (int) $alreadyLiquidatedRecords->sum(fn (array $row) => (int) $row['record']->quantity);
        $professionalTotal = round((float) $recordsWithAmounts->sum('allocated_amount'), 2);
        $alreadyLiquidatedAmount = round((float) $alreadyLiquidatedRecords->sum('allocated_amount'), 2);
        $fileStem = sprintf(
            '%s-%s-%s',
            Str::slug($professional->full_name),
            Carbon::parse($period['start_date'])->format('d-m-Y'),
            Carbon::parse($period['end_date'])->format('d-m-Y'),
        );

        return [
            'professional' => [
                'id' => $professional->id,
                'full_name' => $professional->full_name,
                'area_name' => $professional->area_name,
                'email' => $professional->email,
                'iban' => $professional->iban,
                'iban_display' => IbanFormatter::format($professional->iban),
            ],
            'period' => $period,
            'generated_at' => now()->toIso8601String(),
            'records' => $recordsWithAmounts->map(fn (array $row) => [
                'performed_at' => $row['record']->performed_at?->toDateString(),
                'service_name' => $row['record']->service_name_snapshot,
                'quantity' => (int) $row['record']->quantity,
                'unit_amount' => (float) $row['record']->unit_amount,
                'total_amount' => (float) $row['record']->total_amount,
                'professional_amount' => (float) $row['allocated_amount'],
                'payment_status' => $this->paymentStatusValue($row['record']),
                'payment_status_label' => $this->paymentStatusLabel($row['record']),
                'is_invoiced' => false,
                'invoicing_status' => 'Da fatturare',
                'notes' => $this->statementNotesFor($row['record']),
            ])->all(),
            'totals' => [
                'performance_count' => $performanceCount,
                'records_count' => $recordsWithAmounts->count(),
                'already_liquidated_count' => $alreadyLiquidatedCount,
                'professional_amount' => $professionalTotal,
                'already_liquidated_amount' => $alreadyLiquidatedAmount,
            ],
            'message' => sprintf('Totale quote professionista da fatturare: %s', $this->formatEuro($professionalTotal)),
            'email_subject' => sprintf('Invio prospetto %s - %s', $professional->full_name, $period['label']),
            'email_body' => implode("\n", array_filter([
                'Buongiorno,',
                '',
                sprintf("in allegato trovi il prospetto relativo alle prestazioni non ancora fatturate dell'intervallo %s.", $period['label']),
                sprintf('Totale quote professionista da fatturare nel periodo: %s.', $this->formatEuro($professionalTotal)),
                $alreadyLiquidatedRecords->isNotEmpty()
                    ? sprintf('Tra le quote ancora da fatturare, %d risultano gia liquidate per %s.', $alreadyLiquidatedCount, $this->formatEuro($alreadyLiquidatedAmount))
                    : null,
                '',
                'Resto a disposizione per eventuali verifiche.',
                '',
                'Cordiali saluti',
            ], static fn (?string $line) => $line !== null)),
            'file_name_pdf' => $fileStem.'.pdf',
            'file_name_excel' => $fileStem.'.xlsx',
        ];
    }

    public function downloadPdf(Professional $professional, ?string $startDate = null, ?string $endDate = null): Response
    {
        $statement = $this->build($professional, $startDate, $endDate);
        $pdf = Pdf::loadView('pdf.professional-statement', [
            'statement' => $statement,
            'logoSvg' => $this->logoSvg(),
        ]);

        return $pdf->download($statement['file_name_pdf']);
    }

    public function downloadExcel(Professional $professional, ?string $startDate = null, ?string $endDate = null): BinaryFileResponse
    {
        $statement = $this->build($professional, $startDate, $endDate);

        return Excel::download(
            new ProfessionalStatementWorkbookExport($statement),
            $statement['file_name_excel'],
        );
    }

    private function allocatedAmountForProfessional(PerformanceRecord $record, int $professionalId): float
    {
        $splitMode = $record->split_mode?->value ?? $record->split_mode;
        if ($splitMode === PerformanceSplitMode::Advanced->value) {
            /** @var Collection<int, PerformanceRecordSplit> $splits */
            $splits = $record->relationLoaded('splits') ? $record->splits : $record->splits()->get();

            return round((float) $splits
                ->filter(fn (PerformanceRecordSplit $split) => ($split->subject_type?->value ?? $split->subject_type) === PerformanceSplitSubjectType::Professional->value)
                ->where('professional_id', $professionalId)
                ->sum('amount'), 2);
        }

        return (int) $record->professional_id === $professionalId
            ? round((float) $record->professional_amount, 2)
            : 0.0;
    }

    private function resolvePeriod(?string $startDate, ?string $endDate): array
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->startOfMonth();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay();

        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [
            'label' => sprintf('%s - %s', $start->format('d/m/Y'), $end->format('d/m/Y')),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
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

    private function formatEuro(float $amount): string
    {
        return "\u{20AC} ".number_format($amount, 2, ',', '.');
    }

    private function paymentStatusValue(PerformanceRecord $record): string
    {
        return $record->payment_status?->value ?? $record->payment_status ?? PaymentStatus::DaPagare->value;
    }

    private function paymentStatusLabel(PerformanceRecord $record): string
    {
        return $this->paymentStatusValue($record) === PaymentStatus::Pagata->value
            ? 'Liquidata'
            : 'Da liquidare';
    }

    private function statementNotesFor(PerformanceRecord $record): ?string
    {
        $parts = [];

        if ($this->paymentStatusValue($record) === PaymentStatus::Pagata->value) {
            $parts[] = 'Gia liquidata';
        }

        $note = trim((string) ($record->notes ?? ''));
        if ($note !== '') {
            $parts[] = $note;
        }

        return $parts !== [] ? implode(' | ', $parts) : null;
    }
}
