<?php

namespace App\Enums;

enum CompensationMode: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
}
