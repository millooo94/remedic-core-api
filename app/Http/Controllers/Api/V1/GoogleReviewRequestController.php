<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\GoogleReviewRequestResource;
use App\Models\GoogleReviewRequest;
use App\Services\GoogleReviewRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleReviewRequestController extends Controller
{
    public function __construct(
        private readonly GoogleReviewRequestService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(
            GoogleReviewRequestResource::collection($this->service->list($filters))->response()->getData(true)
        );
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->service->settings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'google_review_url' => ['nullable', 'url', 'max:2048'],
        ]);

        return response()->json([
            'message' => 'Link recensione Google salvato.',
            'settings' => $this->service->updateSettings($payload),
        ]);
    }

    public function reschedule(Request $request, GoogleReviewRequest $googleReviewRequest): JsonResponse
    {
        $payload = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        $record = $this->service->reschedule(
            $googleReviewRequest,
            $payload['scheduled_at'],
            $request->user(),
        );

        return response()->json([
            'message' => 'Invio recensione riprogrammato.',
            'data' => new GoogleReviewRequestResource($record->load(['performanceRecord', 'patient', 'professional'])),
        ]);
    }

    public function cancel(Request $request, GoogleReviewRequest $googleReviewRequest): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = $this->service->cancel(
            $googleReviewRequest,
            $request->user(),
            $payload['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Invio recensione annullato.',
            'data' => new GoogleReviewRequestResource($record->load(['performanceRecord', 'patient', 'professional'])),
        ]);
    }

    public function exclude(Request $request, GoogleReviewRequest $googleReviewRequest): JsonResponse
    {
        $record = $this->service->exclude($googleReviewRequest, $request->user());

        return response()->json([
            'message' => 'Richiesta recensione esclusa dalla coda.',
            'data' => new GoogleReviewRequestResource($record->load(['performanceRecord', 'patient', 'professional'])),
        ]);
    }

    public function retry(GoogleReviewRequest $googleReviewRequest): JsonResponse
    {
        $record = $this->service->retry($googleReviewRequest);

        return response()->json([
            'message' => 'Richiesta recensione reinserita in coda.',
            'data' => new GoogleReviewRequestResource($record->load(['performanceRecord', 'patient', 'professional'])),
        ]);
    }

    public function sendNow(GoogleReviewRequest $googleReviewRequest): JsonResponse
    {
        $record = $this->service->sendNow($googleReviewRequest);

        return response()->json([
            'message' => $record->status === GoogleReviewRequestService::STATUS_SENT
                ? 'Messaggio WhatsApp inviato correttamente.'
                : ($record->error_message ?: 'Invio non completato.'),
            'data' => new GoogleReviewRequestResource($record->load(['performanceRecord', 'patient', 'professional'])),
        ]);
    }

    public function destroy(GoogleReviewRequest $googleReviewRequest): JsonResponse
    {
        $this->service->deleteCancelled($googleReviewRequest);

        return response()->json([
            'message' => 'Richiesta recensione annullata eliminata.',
        ]);
    }
}
