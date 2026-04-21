<?php

namespace App\Services;

use App\Exports\ProfessionalStatementWorkbookExport;
use App\Models\PerformanceRecord;
use App\Models\Professional;
use App\Support\Professionals\IbanFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfessionalStatementService
{
    public function build(Professional $professional, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->resolvePeriod($startDate, $endDate);

        $records = PerformanceRecord::query()
            ->where('professional_id', $professional->id)
            ->whereDate('performed_at', '>=', $period['start_date'])
            ->whereDate('performed_at', '<=', $period['end_date'])
            ->orderBy('performed_at')
            ->get();

        $payableRecords = $records->where('is_invoiced', false)->values();
        $alreadyInvoicedRecords = $records->where('is_invoiced', true)->values();
        $professionalTotal = round((float) $payableRecords->sum('professional_amount'), 2);
        $alreadyInvoicedAmount = round((float) $alreadyInvoicedRecords->sum('professional_amount'), 2);
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
            'records' => $records->map(fn (PerformanceRecord $record) => [
                'performed_at' => $record->performed_at?->toDateString(),
                'service_name' => $record->service_name_snapshot,
                'quantity' => (float) $record->quantity,
                'unit_amount' => (float) $record->unit_amount,
                'total_amount' => (float) $record->total_amount,
                'professional_amount' => (float) $record->professional_amount,
                'is_invoiced' => (bool) $record->is_invoiced,
                'invoicing_status' => $record->is_invoiced ? 'Gia fatturata' : 'Da fatturare',
                'notes' => $record->notes,
            ])->all(),
            'totals' => [
                'performance_count' => $payableRecords->count(),
                'records_count' => $records->count(),
                'already_invoiced_count' => $alreadyInvoicedRecords->count(),
                'professional_amount' => $professionalTotal,
                'already_invoiced_amount' => $alreadyInvoicedAmount,
            ],
            'message' => sprintf('Totale da fatturare a Humancare Telemedicine S.r.l.: %s', $this->formatEuro($professionalTotal)),
            'email_subject' => sprintf('Invio prospetto %s - %s', $professional->full_name, $period['label']),
            'email_body' => implode("\n", array_filter([
                'Buongiorno,',
                '',
                sprintf("in allegato trovi il prospetto relativo all'intervallo %s.", $period['label']),
                sprintf('Totale da fatturare a Humancare Telemedicine S.r.l.: %s.', $this->formatEuro($professionalTotal)),
                $alreadyInvoicedRecords->isNotEmpty()
                    ? sprintf('Prestazioni gia fatturate escluse dal conteggio: %d (%s).', $alreadyInvoicedRecords->count(), $this->formatEuro($alreadyInvoicedAmount))
                    : null,
                '',
                'Resto a disposizione per eventuali verifiche.',
                '',
                'Cordiali saluti',
            ], fn (?string $line) => $line !== null)),
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
        return '€ '.number_format($amount, 2, ',', '.');
    }
}
