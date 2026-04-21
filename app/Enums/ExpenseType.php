<?php

namespace App\Enums;

enum ExpenseType: string
{
    case Fixed = 'fixed';
    case Variable = 'variable';
}
