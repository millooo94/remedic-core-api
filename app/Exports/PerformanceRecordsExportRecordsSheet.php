<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PerformanceRecordsExportRecordsSheet implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(
        private readonly array $exportData,
    ) {
    }

    public function array(): array
    {
        $rows = [
            [mb_strtoupper((string) $this->exportData['document_title'])],
            ['Intervallo: '.$this->exportData['period']['label']],
            [],
            ['Data', 'Professionista', 'Area', 'Prestazione', 'Quantita', 'Importo Prestazione', 'Quota Professionista', 'Liquidazione', 'Fatturazione', 'Tipo', 'Note'],
        ];

        foreach ($this->exportData['records'] as $record) {
            $rows[] = [
                $record['performed_at'],
                $record['professional_name'],
                $record['area_name'],
                $record['service_name'],
                $record['quantity'],
                $record['total_amount'],
                $record['professional_amount'],
                $record['payment_status_label'],
                $record['invoicing_status'],
                $record['fiscal_type_label'],
                $record['notes'],
            ];
        }

        $rows[] = [];
        $rows[] = ['', '', '', 'Totale quota professionista filtrata', $this->exportData['totals']['performance_count'], '', $this->exportData['totals']['professional_amount'], '', '', '', ''];
        $rows[] = [];
        $rows[] = ['Riepilogo quota professionista per professionista'];
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

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $dataStartRow = 5;
                $dataEndRow = max($dataStartRow - 1, 4 + count($this->exportData['records']));
                $totalRow = $dataEndRow + 2;
                $subtotalTitleRow = $totalRow + 2;
                $subtotalHeaderRow = $subtotalTitleRow + 1;
                $subtotalRows = array_sum(array_map(
                    static fn (array $subtotal): int => count($subtotal['fiscal_breakdown'] ?? []),
                    $this->exportData['professional_subtotals'] ?? [],
                ));
                $subtotalStartRow = $subtotalHeaderRow + 1;
                $subtotalEndRow = $subtotalRows > 0 ? $subtotalStartRow + $subtotalRows - 1 : $subtotalStartRow;
                $messageRow = ($subtotalRows > 0 ? $subtotalEndRow : $subtotalHeaderRow) + 2;

                $sheet->mergeCells('A1:K1');
                $sheet->mergeCells('A2:K2');
                $sheet->mergeCells("A{$subtotalTitleRow}:E{$subtotalTitleRow}");
                $sheet->mergeCells("A{$messageRow}:K{$messageRow}");
                $sheet->freezePane('A5');

                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1C9EBD']],
                ]);

                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '5F7383']],
                ]);

                $sheet->getStyle('A4:K4')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF5F8']],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9E5EA'],
                        ],
                    ],
                ]);

                if ($dataEndRow >= $dataStartRow) {
                    $sheet->getStyle("A{$dataStartRow}:K{$dataEndRow}")->applyFromArray([
                        'font' => ['size' => 10],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'E4ECEF'],
                            ],
                        ],
                    ]);

                    $sheet->getStyle("A{$dataStartRow}:A{$dataEndRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                    $sheet->getStyle("F{$dataStartRow}:G{$dataEndRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');
                    $sheet->getStyle("E{$dataStartRow}:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                $sheet->getStyle("D{$totalRow}:G{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F9FB']],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9E5EA'],
                        ],
                    ],
                ]);
                $sheet->getStyle("G{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');
                $sheet->getStyle("E{$totalRow}:G{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $sheet->getStyle("A{$subtotalTitleRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
                ]);

                $sheet->getStyle("A{$subtotalHeaderRow}:E{$subtotalHeaderRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '12384A']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF5F8']],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9E5EA'],
                        ],
                    ],
                ]);

                if ($subtotalRows > 0) {
                    $sheet->getStyle("A{$subtotalStartRow}:E{$subtotalEndRow}")->applyFromArray([
                        'font' => ['size' => 10],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'E4ECEF'],
                            ],
                        ],
                    ]);

                    $sheet->getStyle("C{$subtotalStartRow}:E{$subtotalEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle("E{$subtotalStartRow}:E{$subtotalEndRow}")->getNumberFormat()->setFormatCode('#,##0.00 [$EUR-410]');
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
            },
        ];
    }

    public function title(): string
    {
        return 'Prestazioni';
    }
}
