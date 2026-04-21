<?php

namespace App\Support\Professionals;

use Illuminate\Support\Str;

class IbanFormatter
{
    public static function normalize(?string $value): ?string
    {
        $normalized = Str::upper(preg_replace('/\s+/u', '', trim((string) $value)) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    public static function format(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        return trim(chunk_split($normalized, 4, ' '));
    }
}
