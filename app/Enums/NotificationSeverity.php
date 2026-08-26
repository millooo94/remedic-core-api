<?php

namespace App\Enums;

enum NotificationSeverity: string
{
    case INFO = 'info';
    case SUCCESS = 'success';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
}
