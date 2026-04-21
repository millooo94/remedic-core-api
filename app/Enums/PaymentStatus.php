<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case DaPagare = 'da_pagare';
    case Pagata = 'pagata';
}
