<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PerformanceRecordsExportWorkbook implements WithMultipleSheets
{
    public function __construct(
        private readonly array $exportData,
    ) {
    }

    public function sheets(): array
    {
        return [
            new PerformanceRecordsExportSummarySheet($this->exportData),
            new PerformanceRecordsExportRecordsSheet($this->exportData),
        ];
    }
}
