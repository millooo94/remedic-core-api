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

class PerformanceRecordsExportSummarySheet implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(
        private readonly array $exportData,
    ) {
    }

    public function array(): array
    {
        $rows = [
            [mb_strtoupper((string) $this->exportData['document_title'])],
            ['Data generazione: '.Carbon::parse($this->exportData['generated_at'])->format('d/m/Y H:i')],
            [],
            ['Intervallo considerato', $this->exportData['period']['label']],
            ['Professionisti coinvolti', $this->exportData['totals']['professional_count']],
            ['Prestazioni esportate', $this->exportData['totals']['performance_count']],
            ['Righe esportate', $this->exportData['totals']['records_count']],
            ['Prestazioni liquidate', $this->exportData['totals']['liquidated_count']],
            ['Prestazioni fatturate', $this->exportData['totals']['invoiced_count']],
            ['Totale quota professionista filtrata', $this->exportData['totals']['professional_amount']],
            [],
            ['Riepilogo White / Black'],
            ['Tipo', 'Prestazioni', 'Righe', 'Quota Professionista'],
        ];

        foreach ($this->exportData['fiscal_breakdown'] as $row) {
            $rows[] = [
                $row['label'],
                $row['performance_count'],
                $row['records_count'],
                $row['professional_amount'],
            ];
        }

        $rows[] = [];
        $rows[] = ['Riepilogo per professionista'];
        $rows[] = ['Professionista', 'Tipo', 'Prestazioni', 'Righe', 'Quota Professionista'];

        foreach ($this->exportData['professional_subtotals'] as $subtotal) {
            foreach ($subtotal['fiscal_breakdown'] as $index => $row) {
                $rows[] = [
                    $index === 0 ? $subtotal['professional_name'] : '',
                    $row['label'],
                    $row['performance_count'],
                    $row['records_count'],
                    $row['professional_amount'],
                ];
            }
        }

        $rows[] = [];
        $rows[] = [$this->exportData['message']];

        if ($this->exportData['applied_filters'] !== []) {
            $rows[] = [];
            $rows[] = ['Filtri applicati'];

            foreach ($this->exportData['applied_filters'] as $label) {
                $rows[] = [$label];
            }
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $summaryStartRow = 4;
                $summaryEndRow = 10;
                $breakdownTitleRow = 12;
                $breakdownHeaderRow = 13;
                $breakdownStartRow = 14;
                $breakdownEndRow = $breakdownStartRow + count($this->exportData['fiscal_breakdown']) - 1;
                $professionalTitleRow = $breakdownEndRow + 2;
                $professionalHeaderRow = $professionalTitleRow + 1;
                $professionalRows = array_sum(array_map(
                    static fn (array $subtotal): int => count($subtotal['fiscal_breakdown'] ?? []),
                    $this->exportData['professional_subtotals'] ?? [],
                ));
                $professionalStartRow = $professionalHeaderRow + 1;
                $professionalEndRow = $professionalRows > 0 ? $professionalStartRow + $professionalRows - 1 : $professionalStartRow;
                $messageRow = ($professionalRows > 0 ? $professionalEndRow : $professionalHeaderRow) + 2;
                $filtersTitleRow = $messageRow + 2;
                $filtersStartRow = $filtersTitleRow + 1;
                $filtersEndRow = $filtersStartRow + max(count($this->exportData['applied_filters']) - 1, 0);

                $sheet->mergeCells('A1:E1');
                $sheet->mergeCells('A2:E2');
                $sheet->mergeCells("A{$breakdownTitleRow}:D{$breakdownTitleRow}");
                $sheet->mergeCells("A{$professionalTitleRow}:E{$professionalTitleRow}");
                $sheet->mergeCells("A{$messageRow}:E{$messageRow}");

                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1C9EBD']],
                ]);

                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '5F7383']],
                ]);

                $sheet->getStyle("A{$summaryStartRow}:B{$summaryEndRow}")->applyFromArray([
                    'font' => ['size' => 11],
                    'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9E5EA'],
                        ],
                    ],
                ]);

                $sheet->getStyle("A{$summaryStartRow}:A{$summaryEndRow}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '12384A']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F9FB']],
                ]);

                $sheet->getStyle("B{$summaryEndRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');
                $sheet->getStyle("B{$summaryStartRow}:B{$summaryEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $sheet->getStyle("A{$breakdownTitleRow}")->applyFromArray($this->sectionTitleStyle());
                $sheet->getStyle("A{$breakdownHeaderRow}:D{$breakdownHeaderRow}")->applyFromArray($this->tableHeaderStyle());
                $sheet->getStyle("A{$breakdownStartRow}:D{$breakdownEndRow}")->applyFromArray($this->tableBodyStyle());
                $sheet->getStyle("B{$breakdownStartRow}:D{$breakdownEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("D{$breakdownStartRow}:D{$breakdownEndRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');

                $sheet->getStyle("A{$professionalTitleRow}")->applyFromArray($this->sectionTitleStyle());
                $sheet->getStyle("A{$professionalHeaderRow}:E{$professionalHeaderRow}")->applyFromArray($this->tableHeaderStyle());
                if ($professionalRows > 0) {
                    $sheet->getStyle("A{$professionalStartRow}:E{$professionalEndRow}")->applyFromArray($this->tableBodyStyle());
                    $sheet->getStyle("C{$professionalStartRow}:E{$professionalEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle("E{$professionalStartRow}:E{$professionalEndRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');
                }

                $sheet->getStyle("A{$messageRow}")->applyFromArray([
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

                if ($this->exportData['applied_filters'] !== []) {
                    $sheet->getStyle("A{$filtersTitleRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
                    ]);
                    foreach (range($filtersStartRow, $filtersEndRow) as $row) {
                        $sheet->mergeCells("A{$row}:E{$row}");
                        $sheet->getStyle("A{$row}:E{$row}")->applyFromArray([
                            'font' => ['size' => 10],
                            'alignment' => ['wrapText' => true],
                        ]);
                    }
                }

                $sheet->getColumnDimension('A')->setWidth(34);
                $sheet->getColumnDimension('B')->setWidth(18);
                $sheet->getColumnDimension('C')->setWidth(14);
                $sheet->getColumnDimension('D')->setWidth(18);
                $sheet->getColumnDimension('E')->setWidth(20);
            },
        ];
    }

    public function title(): string
    {
        return 'Riepilogo';
    }

    private function sectionTitleStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
        ];
    }

    private function tableHeaderStyle(): array
    {
        return [
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF5F8']],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D9E5EA'],
                ],
            ],
        ];
    }

    private function tableBodyStyle(): array
    {
        return [
            'font' => ['size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'E4ECEF'],
                ],
            ],
        ];
    }
}
