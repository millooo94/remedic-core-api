<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Integrations\UpdateMiodottoreIntegrationRequest;
use App\Services\IntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class IntegrationController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrationService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->integrationService->list(),
        ]);
    }

    public function showMiodottore(): JsonResponse
    {
        return response()->json($this->integrationService->snapshot(IntegrationService::PROVIDER_MIODOTTORE));
    }

    public function showWhatsApp(): JsonResponse
    {
        return response()->json($this->integrationService->snapshot(IntegrationService::PROVIDER_WHATSAPP));
    }

    public function statusMiodottore(): JsonResponse
    {
        return response()->json($this->integrationService->miodottoreStatus());
    }

    public function statusWhatsApp(): JsonResponse
    {
        return response()->json($this->integrationService->whatsAppStatus());
    }

    public function updateMiodottore(UpdateMiodottoreIntegrationRequest $request): JsonResponse
    {
        $snapshot = $this->integrationService->updateMiodottore($request->validated());

        return response()->json([
            'message' => 'Configurazione MioDottore salvata.',
            'integration' => $snapshot,
        ]);
    }

    public function updateWhatsApp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'enabled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $snapshot = $this->integrationService->updateWhatsApp($payload);

        return response()->json([
            'message' => 'Configurazione WhatsApp aggiornata.',
            'integration' => $snapshot,
        ]);
    }

    public function testMiodottoreConnection(): JsonResponse
    {
        $result = $this->integrationService->verifyMiodottoreAccess();

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function testWhatsAppConnection(): JsonResponse
    {
        return response()->json(
            $this->integrationService->testWhatsAppConnection(),
            Response::HTTP_ACCEPTED
        );
    }

    public function reconnectWhatsApp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'reset_session' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->integrationService->reconnectWhatsApp((bool) ($payload['reset_session'] ?? false)),
            Response::HTTP_ACCEPTED
        );
    }

    public function connectWhatsApp(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'reset_session' => ['nullable', 'boolean'],
        ]);

        return response()->json(
            $this->integrationService->connectWhatsApp((bool) ($payload['reset_session'] ?? true)),
            Response::HTTP_ACCEPTED
        );
    }

    public function pairWhatsApp(): JsonResponse
    {
        return response()->json(
            $this->integrationService->pairWhatsApp(),
            Response::HTTP_ACCEPTED
        );
    }

    public function backgroundLoginMiodottore(): JsonResponse
    {
        try {
            $result = $this->integrationService->backgroundLoginMiodottore();
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'action' => 'assisted_login_start',
                'status' => IntegrationService::STATUS_ERROR,
                'integration' => $this->integrationService->snapshot(IntegrationService::PROVIDER_MIODOTTORE),
            ], Response::HTTP_ACCEPTED);
        }

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function startMiodottoreAssistedLogin(): JsonResponse
    {
        try {
            $result = $this->integrationService->startMiodottoreAssistedLogin();
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'action' => 'assisted_login_start',
                'status' => IntegrationService::STATUS_ERROR,
                'integration' => $this->integrationService->snapshot(IntegrationService::PROVIDER_MIODOTTORE),
            ], Response::HTTP_ACCEPTED);
        }

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function verifyMiodottoreAccess(): JsonResponse
    {
        $result = $this->integrationService->verifyMiodottoreAccess();

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function verifyMiodottoreSession(): JsonResponse
    {
        return $this->verifyMiodottoreAccess();
    }

    public function terminateMiodottoreConnection(): JsonResponse
    {
        $result = $this->integrationService->terminateMiodottoreConnection();

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function terminateWhatsAppConnection(): JsonResponse
    {
        return response()->json(
            $this->integrationService->terminateWhatsAppConnection(),
            Response::HTTP_ACCEPTED
        );
    }

    public function disconnectWhatsApp(): JsonResponse
    {
        return $this->terminateWhatsAppConnection();
    }

    public function resetWhatsAppSession(): JsonResponse
    {
        return response()->json(
            $this->integrationService->resetWhatsAppSession(),
            Response::HTTP_ACCEPTED
        );
    }

    public function syncMiodottoreAvailabilities(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'doctor' => ['nullable', 'string', 'max:255'],
            'write' => ['nullable', 'boolean'],
        ]);

        $filters = [
            'days' => $validated['days'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'doctor' => $validated['doctor'] ?? null,
        ];
        $write = ($validated['write'] ?? false) === true;

        $result = $this->integrationService->syncMiodottoreAvailabilities($filters, $write);

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function syncMiodottorePatients(): JsonResponse
    {
        $result = $this->integrationService->runSyncPlaceholder('sync_patients');

        return response()->json($result, Response::HTTP_ACCEPTED);
    }

    public function syncMiodottoreAppointments(): JsonResponse
    {
        $result = $this->integrationService->runSyncPlaceholder('sync_appointments');

        return response()->json($result, Response::HTTP_ACCEPTED);
    }
}
