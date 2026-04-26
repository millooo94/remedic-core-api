<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyCenterSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $summary,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Riepilogo settimanale Remedic Core (%s)', $this->summary['period']['label']),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.weekly-center-summary',
            text: 'mail.weekly-center-summary-text',
        );
    }
}

