<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingWhatsAppController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrationService,
    ) {
    }

    public function status(): JsonResponse
    {
        return response()->json($this->integrationService->whatsAppStatus());
    }

    public function reconnect(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'reset_session' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->integrationService->reconnectWhatsApp((bool) ($payload['reset_session'] ?? false))
        );
    }

    public function resetSession(): JsonResponse
    {
        return response()->json(
            $this->integrationService->resetWhatsAppSession()
        );
    }
}
