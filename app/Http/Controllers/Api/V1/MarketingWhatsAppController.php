<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Marketing\WhatsAppPuppeteerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingWhatsAppController extends Controller
{
    public function __construct(
        private readonly WhatsAppPuppeteerService $whatsAppPuppeteerService,
    ) {
    }

    public function status(): JsonResponse
    {
        return response()->json($this->whatsAppPuppeteerService->status());
    }

    public function reconnect(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'reset_session' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->whatsAppPuppeteerService->reconnect((bool) ($payload['reset_session'] ?? false))
        );
    }
}
