<?php

namespace App\Enums;

enum PromotionValidityBasis: string
{
    case BOOKING_DATE = 'booking_date';
    case APPOINTMENT_DATE = 'appointment_date';
}
