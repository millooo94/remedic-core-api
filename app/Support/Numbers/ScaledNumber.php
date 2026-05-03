<?php

namespace App\Support\Numbers;

use Illuminate\Validation\ValidationException;

final class ScaledNumber
{
    public static function toScaledInteger(
        mixed $value,
        int $scale,
        string $field,
        bool $required = true,
        ?string $requiredMessage = null,
        ?string $invalidMessage = null,
    ): int {
        $normalized = self::normalizeNumericString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => $required
                    ? ($requiredMessage ?? 'Valore obbligatorio.')
                    : ($invalidMessage ?? 'Valore numerico non valido.'),
            ]);
        }

        return self::scaledIntegerFromNormalizedString(
            $normalized,
            $scale,
            $field,
            $invalidMessage,
        );
    }

    public static function assertWholeAmount(
        mixed $value,
        string $field,
        ?string $message = null,
    ): int {
        $cents = self::toScaledInteger(
            value: $value,
            scale: 2,
            field: $field,
            required: true,
            requiredMessage: 'Valore obbligatorio.',
            invalidMessage: $message ?? 'Inserisci un importo intero senza centesimi.',
        );

        if ($cents % 100 !== 0) {
            throw ValidationException::withMessages([
                $field => $message ?? 'Inserisci un importo intero senza centesimi.',
            ]);
        }

        return $cents;
    }

    public static function fromScaledInteger(int $value, int $scale): string
    {
        return number_format($value / (10 ** $scale), $scale, '.', '');
    }

    public static function normalizeNumericString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = is_string($value) ? $value : (string) $value;
        $raw = trim(str_replace("\xc2\xa0", ' ', $raw));

        if ($raw === '') {
            return null;
        }

        $raw = str_replace([' ', "'", '_'], '', $raw);
        $sign = '';

        if (str_starts_with($raw, '-')) {
            $sign = '-';
            $raw = substr($raw, 1);
        }

        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $raw = str_replace('.', '', $raw);
                $raw = str_replace(',', '.', $raw);
            } else {
                $raw = str_replace(',', '', $raw);
            }
        } elseif ($lastComma !== false) {
            $raw = str_replace(',', '.', $raw);
        }

        if (! preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            return null;
        }

        return $sign.$raw;
    }

    private static function scaledIntegerFromNormalizedString(
        string $normalized,
        int $scale,
        string $field,
        ?string $invalidMessage = null,
    ): int {
        $negative = str_starts_with($normalized, '-');
        $body = $negative ? substr($normalized, 1) : $normalized;
        [$wholePart, $fractionPart] = array_pad(explode('.', $body, 2), 2, '');

        if ($wholePart === '') {
            throw ValidationException::withMessages([
                $field => $invalidMessage ?? 'Valore numerico non valido.',
            ]);
        }

        $wholePart = ltrim($wholePart, '0');
        $wholePart = $wholePart === '' ? '0' : $wholePart;
        $fractionDigits = preg_replace('/\D/', '', $fractionPart) ?? '';
        $roundDigit = (int) ($fractionDigits[$scale] ?? '0');
        $keptFraction = $scale > 0 ? substr($fractionDigits, 0, $scale) : '';
        $multiplier = 10 ** $scale;

        $scaled = ((int) $wholePart) * $multiplier;

        if ($scale > 0) {
            $scaled += (int) str_pad($keptFraction, $scale, '0');
        }

        if ($roundDigit >= 5) {
            $scaled += 1;
        }

        return $negative ? -$scaled : $scaled;
    }
}
