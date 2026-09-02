<?php

namespace App\Rules;

use App\Enums\SupportedLocale;
use App\Services\LocalizedRouteRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AvailableCustomPageSlug implements ValidationRule
{
    public function __construct(private readonly SupportedLocale $locale) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(LocalizedRouteRegistry::class)->isReservedPageSlug($value, $this->locale)) {
            return;
        }

        $fail('Lo slug Ã¨ riservato a una route strutturale del sito.');
    }
}
