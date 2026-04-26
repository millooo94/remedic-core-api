<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminAccessRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $approvalUrl,
        public string $rejectUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[ATTENZIONE] Nuova richiesta di accesso alla dashboard',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.admin-access-request',
            text: 'mail.admin-access-request-text',
            with: [
                'user' => $this->user,
                'approvalUrl' => $this->approvalUrl,
                'rejectUrl' => $this->rejectUrl,
                'requestedAt' => $this->user->approval_requested_at ?? $this->user->created_at,
            ],
        );
    }
}
