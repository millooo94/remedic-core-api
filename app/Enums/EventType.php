<?php

namespace App\Enums;

enum EventType: string
{
    case OPEN_DAY = 'open_day';
    case SCREENING = 'screening';
    case MEETING = 'meeting';
    case WEBINAR = 'webinar';
    case OTHER = 'other';
}
