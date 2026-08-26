<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NewsletterSubscriberStatus;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NewsletterSubscriberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(NewsletterSubscriberStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $subscribers = NewsletterSubscriber::query()
            ->when($validated['q'] ?? null, fn ($query, string $q) => $query->where('email', 'like', '%'.mb_strtolower(trim($q)).'%'))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($subscribers->through(fn (NewsletterSubscriber $subscriber): array => $this->listProjection($subscriber)));
    }

    public function show(NewsletterSubscriber $newsletterSubscriber): JsonResponse
    {
        $newsletterSubscriber->load('consentEvents');

        return response()->json(['data' => [
            ...$this->listProjection($newsletterSubscriber),
            'events' => $newsletterSubscriber->consentEvents->map(fn ($event): array => [
                'event_type' => $event->event_type->value,
                'consent_version' => $event->consent_version,
                'occurred_at' => $event->occurred_at?->toISOString(),
            ])->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function listProjection(NewsletterSubscriber $subscriber): array
    {
        return [
            'public_id' => $subscriber->public_id,
            'email' => $subscriber->email,
            'status' => $subscriber->status->value,
            'consent_version' => $subscriber->consent_version,
            'consent_requested_at' => $subscriber->consent_requested_at?->toISOString(),
            'confirmation_sent_at' => $subscriber->confirmation_sent_at?->toISOString(),
            'confirmed_at' => $subscriber->confirmed_at?->toISOString(),
            'unsubscribed_at' => $subscriber->unsubscribed_at?->toISOString(),
            'created_at' => $subscriber->created_at?->toISOString(),
        ];
    }
}
