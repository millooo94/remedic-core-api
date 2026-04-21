<?php

namespace App\Enums;

enum ExpenseRecurrence: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Manual = 'manual';
}
