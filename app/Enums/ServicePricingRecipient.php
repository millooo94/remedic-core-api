<?php

namespace App\Enums;

enum ServicePricingRecipient: string
{
    case Unspecified = 'unspecified';
    case Male = 'male';
    case Female = 'female';
}
