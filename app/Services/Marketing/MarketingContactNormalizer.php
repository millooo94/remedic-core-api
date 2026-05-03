<?php

namespace App\Services\Marketing;

class MarketingContactNormalizer
{
    public function normalizePhone(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $normalized = preg_replace('/(?!^\+)[^0-9]/', '', $raw) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '00')) {
            $normalized = '+'.substr($normalized, 2);
        }

        if (! str_starts_with($normalized, '+')) {
            $defaultPrefix = trim((string) config('services.twilio.default_country_code', '+39'));
            $digitsOnly = preg_replace('/\D+/', '', $normalized) ?? '';

            if ($digitsOnly === '') {
                return null;
            }

            $normalized = $defaultPrefix.$digitsOnly;
        }

        return $normalized;
    }

    public function normalizeEmail(?string $value): ?string
    {
        $email = strtolower(trim((string) $value));

        return $email !== '' ? $email : null;
    }
}
