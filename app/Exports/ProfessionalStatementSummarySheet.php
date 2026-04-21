<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ProfessionalStatementSummarySheet implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(
        private readonly array $statement,
    ) {
    }

    public function array(): array
    {
        return [
            ['PROSPETTO PROFESSIONISTA'],
            ['Data generazione: '.Carbon::parse($this->statement['generated_at'])->format('d/m/Y H:i')],
            [],
            ['Professionista', $this->statement['professional']['full_name']],
            ['Area', $this->statement['professional']['area_name']],
            ['IBAN', $this->statement['professional']['iban_display'] ?? 'Non indicato'],
            ['Intervallo considerato', $this->statement['period']['label']],
            ['Prestazioni conteggiate', $this->statement['totals']['performance_count']],
            ['Gia fatturate', $this->statement['totals']['already_invoiced_count']],
            ['Gia fatturate escluse', $this->statement['totals']['already_invoiced_amount']],
            ['Totale da fatturare', $this->statement['totals']['professional_amount']],
            [],
            [$this->statement['message']],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:B1');
                $sheet->mergeCells('A2:B2');
                $sheet->mergeCells('A13:B13');

                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1C9EBD']],
                ]);

                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '5F7383']],
                ]);

                $sheet->getStyle('A4:B11')->applyFromArray([
                    'font' => ['size' => 11],
                    'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9E5EA'],
                        ],
                    ],
                ]);

                $sheet->getStyle('A4:A11')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '12384A']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F9FB']],
                ]);

                $sheet->getStyle('B10:B11')->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');
                $sheet->getStyle('B10:B11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $sheet->getStyle('A13')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
                    'alignment' => ['wrapText' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDF7D2']],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D5E2A4'],
                        ],
                    ],
                ]);

                $sheet->getColumnDimension('A')->setWidth(30);
                $sheet->getColumnDimension('B')->setWidth(48);

                foreach ([1 => 24, 2 => 18, 4 => 22, 5 => 22, 6 => 22, 7 => 22, 8 => 22, 9 => 22, 10 => 22, 11 => 22, 13 => 34] as $row => $height) {
                    $sheet->getRowDimension($row)->setRowHeight($height);
                }
            },
        ];
    }

    public function title(): string
    {
        return 'Riepilogo';
    }
}
