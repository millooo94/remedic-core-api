<?php

namespace App\Enums;

enum ConsentEventType: string
{
    case ACCEPT_ALL = 'accept_all';
    case REJECT_ALL = 'reject_all';
    case SAVE_PREFERENCES = 'save_preferences';
    case WITHDRAW = 'withdraw';
    case RECONSENT = 'reconsent';

    public function label(): string
    {
        return match ($this) {
            self::ACCEPT_ALL => 'Accetta tutto',
            self::REJECT_ALL => 'Rifiuta tutto',
            self::SAVE_PREFERENCES => 'Salva preferenze',
            self::WITHDRAW => 'Revoca',
            self::RECONSENT => 'Re-consenso',
        };
    }
}
