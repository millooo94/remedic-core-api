<?php

namespace App\Enums;

enum CashBoxType: string
{
    case Fatturati = 'fatturati';
    case Black = 'black';

    public function label(): string
    {
        return match ($this) {
            self::Fatturati => 'Cassa fatturati',
            self::Black => 'Cassa black',
        };
    }
}
