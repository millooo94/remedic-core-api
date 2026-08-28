<?php

namespace App\Providers;

use App\Contracts\TranslationProvider;
use App\Models\BlogPost;
use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Models\ContentTranslation;
use App\Models\ExpenseRecord;
use App\Models\FaqItem;
use App\Models\FaqItemTranslation;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Section;
use App\Models\SectionTranslation;
use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\SiteIndexPageTranslation;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Observers\ExpenseRecordObserver;
use App\Observers\PublicSearchObserver;
use App\Services\Translation\GoogleCloudTranslationProvider;
use App\Support\TestingDatabaseGuard;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TranslationProvider::class, GoogleCloudTranslationProvider::class);
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
        foreach ([Page::class, SiteIndexPage::class, SpecializationWebProfile::class, ServiceWebProfile::class, ProfessionalPublicProfile::class, CheckupWebProfile::class, BlogPost::class, ContentTranslation::class, Section::class, FaqItem::class, SectionTranslation::class, FaqItemTranslation::class, SiteIndexPageTranslation::class, Specialization::class, Service::class, Professional::class, Checkup::class] as $model) {
            $model::observe(PublicSearchObserver::class);
        }

        RateLimiter::for('newsletter-subscribe', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('newsletter:'.$request->ip()));
        RateLimiter::for('consent-mutations', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('consent:'.$request->ip()));
        RateLimiter::for('translation-generations', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('translation:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('career-applications', fn (Request $request): Limit => Limit::perMinute(5)
            ->by('career-application:'.$request->ip()));

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
