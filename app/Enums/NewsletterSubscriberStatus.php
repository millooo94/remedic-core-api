<?php

namespace App\Enums;

enum NewsletterSubscriberStatus: string
{
    case PENDING = 'pending';
    case SUBSCRIBED = 'subscribed';
    case UNSUBSCRIBED = 'unsubscribed';
}
