<?php

namespace App\Enums;

enum SupportedLocale: string
{
    case IT = 'it';
    case EN = 'en';
    case ES = 'es';
    case FR = 'fr';

    public static function default(): self
    {
        return self::IT;
    }

    public static function normalize(?string $locale): ?self
    {
        $value = strtolower(str_replace('_', '-', trim((string) $locale)));
        $value = explode('-', $value)[0] ?: '';

        return self::tryFrom($value);
    }

    public function label(): string
    {
        return match ($this) {
            self::IT => 'Italiano', self::EN => 'English', self::ES => 'Español', self::FR => 'Français',
        };
    }
}
