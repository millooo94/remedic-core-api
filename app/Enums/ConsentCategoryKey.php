<?php

namespace App\Enums;

enum ConsentCategoryKey: string
{
    case NECESSARY = 'necessary';
    case PREFERENCES = 'preferences';
    case STATISTICS = 'statistics';
    case MARKETING = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::NECESSARY => 'Necessari',
            self::PREFERENCES => 'Preferenze',
            self::STATISTICS => 'Statistiche',
            self::MARKETING => 'Marketing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::NECESSARY => 'Tecnologie strettamente necessarie al funzionamento del sito.',
            self::PREFERENCES => 'Servizi che abilitano preferenze, embed o funzionalita opzionali.',
            self::STATISTICS => 'Strumenti di analisi e misurazione del traffico.',
            self::MARKETING => 'Strumenti di advertising, remarketing o profilazione.',
        };
    }

    public function isRequired(): bool
    {
        return $this === self::NECESSARY;
    }
}
