<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WhatsAppDisconnectedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string|null>  $details
     */
    public function __construct(
        public readonly array $details,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Remedic Core - WhatsApp scollegato',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.whatsapp-disconnected',
            text: 'mail.whatsapp-disconnected-text',
        );
    }
}
