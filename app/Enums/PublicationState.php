<?php

namespace App\Enums;

enum PublicationState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Suspended = 'suspended';
}
