<?php

namespace App\Enums;

enum CashMovementType: string
{
    case Versamento = 'versamento';
    case Prelievo = 'prelievo';

    public function label(): string
    {
        return match ($this) {
            self::Versamento => 'Versamento',
            self::Prelievo => 'Prelievo',
        };
    }

    public function sign(): int
    {
        return match ($this) {
            self::Versamento => 1,
            self::Prelievo => -1,
        };
    }
}
