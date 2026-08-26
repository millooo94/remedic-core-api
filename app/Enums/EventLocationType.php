<?php

namespace App\Enums;

enum EventLocationType: string
{
    case REMEDIC = 'remedic';
    case EXTERNAL = 'external';
    case ONLINE = 'online';
}
