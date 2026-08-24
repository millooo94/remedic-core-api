<?php

namespace App\Providers;

use App\Models\ExpenseRecord;
use App\Observers\ExpenseRecordObserver;
use App\Support\TestingDatabaseGuard;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $requestedEnvironment = Env::get('APP_ENV');
        $requestedConnection = null;

        if ($this->app->runningInConsole()) {
            $input = new ArgvInput;

            if ($input->hasParameterOption('--env')
                && $input->getParameterOption('--env') === 'testing') {
                $requestedEnvironment = 'testing';
            }

            if ($input->hasParameterOption('--database')) {
                $requestedConnection = $input->getParameterOption('--database');
            }
        }

        TestingDatabaseGuard::assertConfigurationIsSafe(
            $this->app->make('config'),
            $requestedEnvironment,
            $requestedConnection,
        );
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale', 'it'));
        JsonResource::withoutWrapping();
        ExpenseRecord::observe(ExpenseRecordObserver::class);

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
