<?php

namespace App\Enums;

enum ServicePricingItemKind: string
{
    case Zone = 'zone';
    case Package = 'package';
    case Variant = 'variant';
}
