<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CashMovementDeletedWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $details,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Remedic | [ATTENZIONE] Eliminato movimento di cassa',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.cash-movement-deleted-warning',
            text: 'mail.cash-movement-deleted-warning-text',
        );
    }
}
