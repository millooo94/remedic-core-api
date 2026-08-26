<?php

namespace App\Enums;

enum EventOperationalStatus: string
{
    case PLANNED = 'planned';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
