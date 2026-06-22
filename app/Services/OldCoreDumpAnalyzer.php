<?php

namespace App\Services;

class OldCoreDumpAnalyzer
{
    /**
     * @return array<string, array{columns:list<string>, primary_key:list<string>, foreign_keys:list<array{column:string, references_table:string, references_column:string}>}>
     */
    public function analyze(?string $dumpPath = null): array
    {
        $path = $dumpPath ?? $this->defaultDumpPath();

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=/s', $contents, $matches, PREG_SET_ORDER);

        $tables = [];

        foreach ($matches as $match) {
            $table = $match[1];
            $block = $match[2];
            $lines = preg_split('/\R/', $block) ?: [];

            $columns = [];
            $primaryKey = [];
            $foreignKeys = [];

            foreach ($lines as $line) {
                $trimmed = trim($line, " \t\n\r\0\x0B,");

                if (preg_match('/^`([^`]+)` /', $trimmed, $columnMatch) === 1) {
                    $columns[] = $columnMatch[1];

                    continue;
                }

                if (preg_match('/^PRIMARY KEY \((.+)\)$/', $trimmed, $primaryMatch) === 1) {
                    preg_match_all('/`([^`]+)`/', $primaryMatch[1], $primaryColumns);
                    $primaryKey = $primaryColumns[1];

                    continue;
                }

                if (preg_match('/FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)/', $trimmed, $foreignMatch) === 1) {
                    $foreignKeys[] = [
                        'column' => $foreignMatch[1],
                        'references_table' => $foreignMatch[2],
                        'references_column' => $foreignMatch[3],
                    ];
                }
            }

            $tables[$table] = [
                'columns' => $columns,
                'primary_key' => $primaryKey,
                'foreign_keys' => $foreignKeys,
            ];
        }

        ksort($tables);

        return $tables;
    }

    public function defaultDumpPath(): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.'remedic_core_db.sql';
    }
}
