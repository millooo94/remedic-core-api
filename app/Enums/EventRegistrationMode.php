<?php

namespace App\Enums;

enum EventRegistrationMode: string
{
    case NONE = 'none';
    case CONTACT = 'contact';
    case EXTERNAL_URL = 'external_url';
}
