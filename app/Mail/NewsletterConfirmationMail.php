<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $confirmationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Conferma la tua iscrizione alla newsletter Remedic');
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.newsletter-confirmation',
            text: 'mail.newsletter-confirmation-text',
        );
    }
}
