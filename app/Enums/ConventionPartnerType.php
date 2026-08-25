<?php

namespace App\Enums;

enum ConventionPartnerType: string
{
    case INSURANCE = 'insurance';
    case NETWORK = 'network';
    case FUND = 'fund';
    case ENTITY = 'entity';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::INSURANCE => 'Assicurazione',
            self::NETWORK => 'Network',
            self::FUND => 'Fondo',
            self::ENTITY => 'Ente',
            self::OTHER => 'Altro',
        };
    }

    public function filterLabel(): string
    {
        return match ($this) {
            self::INSURANCE => 'Assicurazioni',
            self::NETWORK => 'Network',
            self::FUND => 'Fondi',
            self::ENTITY => 'Enti',
            self::OTHER => 'Altro',
        };
    }
}
