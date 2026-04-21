<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProfessionalStatementWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $statement,
    ) {
    }

    public function sheets(): array
    {
        return [
            new ProfessionalStatementSummarySheet($this->statement),
            new ProfessionalStatementRecordsSheet($this->statement),
        ];
    }
}
