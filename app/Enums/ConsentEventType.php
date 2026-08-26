<?php

namespace App\Enums;

enum ConsentEventType: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case WITHDRAWN = 'withdrawn';
    case RENEWED = 'renewed';
}
