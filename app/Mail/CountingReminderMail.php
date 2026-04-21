<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class CountingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Carbon $reminderDate,
        public readonly string $companyName,
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.counting-reminder',
            with: [
                'bodyText' => $this->bodyText,
            ],
        );
    }
}
