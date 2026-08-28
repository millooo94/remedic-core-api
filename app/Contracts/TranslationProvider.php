<?php

namespace App\Contracts;

use App\Enums\SupportedLocale;

interface TranslationProvider
{
    /** @param array<string, string> $segments @return array<string, string> */
    public function translate(array $segments, SupportedLocale $target): array;
}
