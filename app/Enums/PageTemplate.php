<?php

namespace App\Enums;

enum PageTemplate: string
{
    case DEFAULT = 'default';
    case HOME = 'home';
    case CONTACT = 'contact';
    case LEGAL = 'legal';
    case LANDING = 'landing';
}
