<?php

namespace App\Mail;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CareerApplicationCandidateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly JobApplication $application) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->copy()['subject']);
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.career-application-candidate',
            text: 'mail.career-application-candidate-text',
            with: ['copy' => $this->copy()],
        );
    }

    /** @return array{subject:string,title:string,intro:string,confirmation:string,type_label:string,closing:string} */
    public function copy(): array
    {
        return match ($this->application->locale) {
            'en' => ['subject' => 'We received your application — Remedic', 'title' => 'Application received', 'intro' => 'Thank you, '.$this->application->first_name.'. We have received your application.', 'confirmation' => 'We will contact you if your profile is of interest.', 'type_label' => 'Application type', 'closing' => 'Remedic'],
            'es' => ['subject' => 'Hemos recibido tu candidatura — Remedic', 'title' => 'Candidatura recibida', 'intro' => 'Gracias, '.$this->application->first_name.'. Hemos recibido tu candidatura.', 'confirmation' => 'Te contactaremos si tu perfil es de interés.', 'type_label' => 'Tipo de candidatura', 'closing' => 'Remedic'],
            'fr' => ['subject' => 'Nous avons reçu votre candidature — Remedic', 'title' => 'Candidature reçue', 'intro' => 'Merci, '.$this->application->first_name.'. Nous avons bien reçu votre candidature.', 'confirmation' => 'Nous vous contacterons si votre profil correspond à nos besoins.', 'type_label' => 'Type de candidature', 'closing' => 'Remedic'],
            default => ['subject' => 'Abbiamo ricevuto la tua candidatura — Remedic', 'title' => 'Candidatura ricevuta', 'intro' => 'Grazie, '.$this->application->first_name.'. Abbiamo ricevuto la tua candidatura.', 'confirmation' => 'Ti contatteremo se il tuo profilo sarà di interesse.', 'type_label' => 'Tipo di candidatura', 'closing' => 'Remedic'],
        };
    }
}
