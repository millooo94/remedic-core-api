<?php

namespace App\Http\Controllers\Api\V1;

use App\Mail\NewsletterConfirmationMail;
use App\Models\NewsletterSubscriber;
use App\Services\NewsletterSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
                route('newsletter.confirm', ['token' => $result['token']]),
            ));
        }

        return response()->json([
            'message' => 'Se l’indirizzo può essere iscritto, riceverai a breve un’email di conferma.',
        ], 202);
    }

    public function confirm(Request $request, NewsletterSubscriptionService $subscriptions): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $subscriber = $subscriptions->confirm($validated['token']);

        if ($subscriber === null) {
            return response()->json([
                'message' => 'Il link di conferma non è valido o è scaduto. Richiedi una nuova iscrizione.',
            ], 422);
        }

        return response()->json(['data' => ['status' => $subscriber->status->value]]);
    }

    public function unsubscribe(string $publicId, NewsletterSubscriptionService $subscriptions): JsonResponse
    {
        $subscriber = NewsletterSubscriber::query()->where('public_id', $publicId)->firstOrFail();
        $subscriber = $subscriptions->unsubscribe($subscriber);

        return response()->json(['data' => ['status' => $subscriber->status->value]]);
    }
}
