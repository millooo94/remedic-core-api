<?php

namespace App\Enums;

enum NewsletterConsentEventType: string
{
    case SUBSCRIPTION_REQUESTED = 'subscription_requested';
    case SUBSCRIPTION_CONFIRMED = 'subscription_confirmed';
    case UNSUBSCRIBED = 'unsubscribed';
}
