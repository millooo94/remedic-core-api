<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class PatientsRowsImport implements ToArray, WithCustomCsvSettings
{
    /**
     * @var array<int, array<int, mixed>>
     */
    public array $rows = [];

    public function array(array $array): void
    {
        $this->rows = $array;
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ',',
            'enclosure' => '"',
            'escape_character' => '\\',
            'contiguous' => false,
            'input_encoding' => 'UTF-8',
        ];
    }
}
