<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale', 'it'));
        JsonResource::withoutWrapping();

        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );
        });

        VerifyEmail::toMailUsing(function (object $notifiable, string $verificationUrl): MailMessage {
            $recipientName = trim((string) ($notifiable->name ?? ''));

            return (new MailMessage)
                ->subject('Conferma il tuo indirizzo email Remedic')
                ->view(
                    [
                        'html' => 'mail.verify-email',
                        'text' => 'mail.verify-email-text',
                    ],
                    [
                        'recipientName' => $recipientName,
                        'verificationUrl' => $verificationUrl,
                    ],
                );
        });
    }
}
