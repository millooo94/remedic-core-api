<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

class ItalianTaxCodeService
{
    private const MONTH_MAP = [
        'A' => 1,
        'B' => 2,
        'C' => 3,
        'D' => 4,
        'E' => 5,
        'H' => 6,
        'L' => 7,
        'M' => 8,
        'P' => 9,
        'R' => 10,
        'S' => 11,
        'T' => 12,
    ];

    public function normalize(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    public function isPlausible(?string $value): bool
    {
        $normalized = $this->normalize($value);
        if (! $normalized) {
            return false;
        }

        return (bool) preg_match('/^[A-Z0-9]{16}$/', $normalized);
    }

    public function extractBirthDate(?string $value): ?string
    {
        $normalized = $this->normalize($value);
        if (! $this->isPlausible($normalized)) {
            return null;
        }

        $yearPart = substr($normalized, 6, 2);
        $monthLetter = substr($normalized, 8, 1);
        $dayPart = substr($normalized, 9, 2);

        if (! isset(self::MONTH_MAP[$monthLetter]) || ! ctype_digit($yearPart.$dayPart)) {
            return null;
        }

        $month = self::MONTH_MAP[$monthLetter];
        $day = (int) $dayPart;
        if ($day > 40) {
            $day -= 40;
        }

        if ($day < 1 || $day > 31) {
            return null;
        }

        $yy = (int) $yearPart;
        $currentYY = (int) now()->format('y');
        $fullYear = $yy > $currentYY ? 1900 + $yy : 2000 + $yy;

        try {
            return CarbonImmutable::createStrict($fullYear, $month, $day)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

