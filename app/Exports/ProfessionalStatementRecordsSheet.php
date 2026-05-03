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

class ProfessionalStatementRecordsSheet implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(
        private readonly array $statement,
    ) {
    }

    public function array(): array
    {
        $rows = [
            ['RIEPILOGO PRESTAZIONI DA FATTURARE'],
            ['Professionista: '.$this->statement['professional']['full_name']],
            ['Intervallo: '.$this->statement['period']['label']],
            [],
            ['Data', 'Prestazione', 'Quantita', 'Importo Prestazione', 'Quota Professionista', 'Liquidazione', 'Note'],
        ];

        foreach ($this->statement['records'] as $record) {
            $rows[] = [
                $record['performed_at'],
                $record['service_name'],
                $record['quantity'],
                $record['total_amount'],
                $record['professional_amount'],
                $record['payment_status_label'],
                $record['notes'],
            ];
        }

        $rows[] = [];
        $rows[] = ['', '', '', '', 'Totale quote da fatturare', $this->statement['totals']['professional_amount'], ''];

        if (($this->statement['totals']['already_liquidated_count'] ?? 0) > 0) {
            $rows[] = [
                '',
                '',
                '',
                '',
                'Di cui gia liquidate',
                $this->statement['totals']['already_liquidated_amount'],
                $this->statement['totals']['already_liquidated_count'].' prestazioni',
            ];
        }

        $rows[] = [$this->statement['message']];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $dataStartRow = 6;
                $dataEndRow = max($dataStartRow - 1, 5 + count($this->statement['records']));
                $totalRow = $dataEndRow + 2;
                $highlightRow = ($this->statement['totals']['already_liquidated_count'] ?? 0) > 0 ? $dataEndRow + 3 : null;
                $messageRow = $highlightRow ? $dataEndRow + 4 : $dataEndRow + 3;

                $sheet->mergeCells('A1:G1');
                $sheet->mergeCells('A2:G2');
                $sheet->mergeCells('A3:G3');
                $sheet->mergeCells("A{$messageRow}:G{$messageRow}");

                $sheet->freezePane('A6');

                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1C9EBD']],
                ]);

                $sheet->getStyle('A2:A3')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '5F7383']],
                ]);

                $sheet->getStyle('A5:G5')->applyFromArray([
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
                    $sheet->getStyle("A{$dataStartRow}:G{$dataEndRow}")->applyFromArray([
                        'font' => ['size' => 10],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'E4ECEF'],
                            ],
                        ],
                    ]);

                    $sheet->getStyle("A{$dataStartRow}:A{$dataEndRow}")
                        ->getNumberFormat()
                        ->setFormatCode('dd/mm/yyyy');
                    $sheet->getStyle("D{$dataStartRow}:E{$dataEndRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00 [$EUR-410]');
                    $sheet->getStyle("C{$dataStartRow}:E{$dataEndRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                $sheet->getStyle("E{$totalRow}:F{$totalRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F9FB']],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9E5EA'],
                        ],
                    ],
                ]);
                $sheet->getStyle("F{$totalRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00 [$EUR-410]');
                $sheet->getStyle("F{$totalRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                if ($highlightRow) {
                    $sheet->getStyle("E{$highlightRow}:G{$highlightRow}")->applyFromArray([
                        'font' => ['size' => 10, 'bold' => true, 'color' => ['rgb' => '0F667D']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5F8']],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'C7E4EB'],
                            ],
                        ],
                    ]);
                    $sheet->getStyle("F{$highlightRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00 [$EUR-410]');
                    $sheet->getStyle("F{$highlightRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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

                $sheet->getColumnDimension('B')->setWidth(48);
                $sheet->getColumnDimension('F')->setWidth(24);
                $sheet->getColumnDimension('G')->setWidth(48);

                foreach ([1 => 24, 5 => 22, $messageRow => 32] as $row => $height) {
                    $sheet->getRowDimension($row)->setRowHeight($height);
                }
            },
        ];
    }

    public function title(): string
    {
        return 'Prestazioni';
    }
}
