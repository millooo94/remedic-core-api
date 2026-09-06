<?php

namespace App\Enums;

enum NewsletterCampaignDeliveryStatus: string
{
    case PENDING = 'pending';
    case SENDING = 'sending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SUPPRESSED = 'suppressed';
}
