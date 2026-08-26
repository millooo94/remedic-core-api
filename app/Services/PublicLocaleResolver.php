<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Resolves the public locale once, without ever silently falling back to Italian. */
class PublicLocaleResolver
{
    public function resolve(Request $request): SupportedLocale
    {
        $value = $request->query('locale');

        if ($value === null || $value === '') {
            return SupportedLocale::default();
        }

        $locale = SupportedLocale::normalize((string) $value);
        if ($locale === null || strtolower((string) $value) !== $locale->value) {
            throw ValidationException::withMessages(['locale' => 'Locale non supportato.']);
        }

        return $locale;
    }
}
