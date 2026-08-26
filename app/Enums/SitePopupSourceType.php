<?php

namespace App\Enums;

enum SitePopupSourceType: string
{
    case MANUAL = 'manual';
    case PROMOTION = 'promotion';
    case EVENT = 'event';
}
