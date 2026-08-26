<?php

return [
    'consent_version' => env('NEWSLETTER_CONSENT_VERSION', 'newsletter-marketing-v1'),
    'confirmation_ttl_hours' => (int) env('NEWSLETTER_CONFIRMATION_TTL_HOURS', 48),
    'resend_cooldown_minutes' => (int) env('NEWSLETTER_RESEND_COOLDOWN_MINUTES', 5),
];
