<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NewsletterCampaignMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly ?string $preheader,
        public readonly string $bodyText,
        public readonly ?string $unsubscribeUrl = null,
        public readonly bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.newsletter-campaign',
            text: 'mail.newsletter-campaign-text',
        );
    }
}
