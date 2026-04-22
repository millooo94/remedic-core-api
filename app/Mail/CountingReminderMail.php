<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CountingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Carbon $reminderDate,
        public readonly string $companyName,
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        $subject = trim($this->subjectLine);

        return new Envelope(
            subject: Str::contains(Str::lower($subject), 'remedic')
                ? $subject
                : 'Remedic | '.$subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.counting-reminder',
            text: 'mail.counting-reminder-text',
        );
    }
}
