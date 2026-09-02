<?php

namespace App\Enums;

enum ServiceClassification: string
{
    case SpecialistVisit = 'specialist_visit';
    case Diagnostic = 'diagnostic';
    case AestheticMedicine = 'aesthetic_medicine';
}
