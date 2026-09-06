<?php

namespace App\Http\Controllers\Api\V1;

use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use App\Services\NewsletterSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use LogicException;

class NewsletterSubscriptionController
{
    public function subscribe(Request $request, NewsletterSubscriptionService $subscriptions): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'consent_accepted' => ['required', 'accepted'],
        ]);
        $result = $subscriptions->requestSubscription($validated['email']);

        if ($result['token'] !== null) {
            Mail::to($result['subscriber']->email)->send(new NewsletterConfirmationMail(
                route('newsletter.confirm', ['token' => $result['token'], 'locale' => $this->locale($request)]),
            ));
        }

        return response()->json([
            'message' => 'If this address can be subscribed, a confirmation email will be sent shortly.',
        ], 202);
    }

    /** Core owns token validation and only redirects to trusted, fixed Website paths. */
    public function confirm(Request $request, NewsletterSubscriptionService $subscriptions): RedirectResponse
    {
        $token = $request->query('token');
        $subscriber = is_string($token) && preg_match('/^[a-f0-9]{64}$/i', $token)
            ? $subscriptions->confirm($token)
            : null;

        return $this->redirectToWebsite($request, 'conferma', $subscriber === null ? 'invalid' : 'confirmed');
    }

    /** The signed URL remains verified by Core; Website receives only a controlled outcome. */
    public function unsubscribe(Request $request, string $publicId, NewsletterSubscriptionService $subscriptions): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->redirectToWebsite($request, 'disiscrizione', 'invalid');
        }

        $subscriber = NewsletterSubscriber::query()->where('public_id', $publicId)->first();
        if ($subscriber === null) {
            return $this->redirectToWebsite($request, 'disiscrizione', 'invalid');
        }

        $alreadyUnsubscribed = $subscriber->status->value === 'unsubscribed';
        $subscriptions->unsubscribe($subscriber);

        return $this->redirectToWebsite($request, 'disiscrizione', $alreadyUnsubscribed ? 'already_unsubscribed' : 'unsubscribed');
    }

    private function redirectToWebsite(Request $request, string $path, string $status): RedirectResponse
    {
        $baseUrl = rtrim((string) config('newsletter.website_url'), '/');
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true) || isset($parts['query'], $parts['fragment'])) {
            throw new LogicException('PUBLIC_WEBSITE_URL must be an absolute HTTP(S) origin without a query string or fragment.');
        }

        $locale = $this->locale($request);
        $prefix = $locale === 'it' ? '' : '/'.$locale;

        return redirect()->away($baseUrl.$prefix.'/newsletter/'.$path.'?status='.rawurlencode($status));
    }

    private function locale(Request $request): string
    {
        $locale = $request->query('locale', 'it');

        return is_string($locale) && in_array($locale, ['it', 'en', 'es', 'fr'], true) ? $locale : 'it';
    }
}
