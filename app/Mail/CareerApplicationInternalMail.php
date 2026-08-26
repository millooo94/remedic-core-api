<?php

namespace App\Mail;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CareerApplicationInternalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly JobApplication $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Nuova candidatura ricevuta — '.$this->application->first_name.' '.$this->application->last_name);
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.career-application-internal',
            text: 'mail.career-application-internal-text',
            with: ['careerUrl' => rtrim((string) config('app.frontend_url'), '/').'/applications?application='.$this->application->public_id],
        );
    }
}
