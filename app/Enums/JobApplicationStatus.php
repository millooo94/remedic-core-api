<?php

namespace App\Enums;

enum JobApplicationStatus: string
{
    case NEW = 'new';
    case IN_REVIEW = 'in_review';
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Nuova', self::IN_REVIEW => 'In valutazione', self::ARCHIVED => 'Archiviata'
        };
    }
}
