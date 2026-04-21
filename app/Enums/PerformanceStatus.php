<?php

namespace App\Enums;

enum PerformanceStatus: string
{
    case DaFatturare = 'da_fatturare';
    case Fatturata = 'fatturata';
    case Pagata = 'pagata';
}
