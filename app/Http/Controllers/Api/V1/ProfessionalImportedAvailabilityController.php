<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Professionals\UpdateProfessionalMiodottoreProfileRequest;
use App\Http\Resources\Api\V1\ProfessionalAvailabilityExceptionResource;
use App\Http\Resources\Api\V1\ProfessionalAvailabilityRuleResource;
use App\Models\Professional;
use App\Services\MiodottoreAvailabilitySyncService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProfessionalImportedAvailabilityController extends Controller
{
    public function __construct(
        private readonly MiodottoreAvailabilitySyncService $syncService,
    ) {
    }

    public function show(Professional $professional): JsonResponse
    {
        $snapshot = $this->syncService->snapshot($professional);

        return response()->json([
            'source' => $snapshot['source'],
            'source_label' => $snapshot['source_label'],
            'provider_profile' => $snapshot['provider_profile'],
            'sync_status' => $snapshot['sync_status'],
            'sync_status_label' => $snapshot['sync_status_label'],
            'last_synced_at' => $snapshot['last_synced_at'],
            'last_sync_error' => $snapshot['last_sync_error'],
            'recurring_rules' => ProfessionalAvailabilityRuleResource::collection($snapshot['rules'])->resolve(),
            'daily_exceptions' => ProfessionalAvailabilityExceptionResource::collection($snapshot['exceptions'])->resolve(),
        ]);
    }

    public function sync(Professional $professional): JsonResponse
    {
        $result = $this->syncService->requestSync($professional);
        $snapshot = $this->syncService->snapshot($professional);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'status' => $result['status'],
            'message' => $result['message'],
            'summary' => $result['summary'] ?? [],
            'source' => $snapshot['source'],
            'source_label' => $snapshot['source_label'],
            'provider_profile' => $snapshot['provider_profile'],
            'sync_status' => $snapshot['sync_status'],
            'sync_status_label' => $snapshot['sync_status_label'],
            'last_synced_at' => $snapshot['last_synced_at'],
            'last_sync_error' => $snapshot['last_sync_error'],
            'recurring_rules' => ProfessionalAvailabilityRuleResource::collection($snapshot['rules'])->resolve(),
            'daily_exceptions' => ProfessionalAvailabilityExceptionResource::collection($snapshot['exceptions'])->resolve(),
        ], Response::HTTP_ACCEPTED);
    }

    public function updateProviderProfile(
        UpdateProfessionalMiodottoreProfileRequest $request,
        Professional $professional,
    ): JsonResponse {
        $this->syncService->updateProviderProfile(
            $professional,
            (string) $request->validated('external_url'),
        );

        $snapshot = $this->syncService->snapshot($professional);

        return response()->json([
            'message' => 'URL MioDottore salvato.',
            'source' => $snapshot['source'],
            'source_label' => $snapshot['source_label'],
            'provider_profile' => $snapshot['provider_profile'],
            'sync_status' => $snapshot['sync_status'],
            'sync_status_label' => $snapshot['sync_status_label'],
            'last_synced_at' => $snapshot['last_synced_at'],
            'last_sync_error' => $snapshot['last_sync_error'],
            'recurring_rules' => ProfessionalAvailabilityRuleResource::collection($snapshot['rules'])->resolve(),
            'daily_exceptions' => ProfessionalAvailabilityExceptionResource::collection($snapshot['exceptions'])->resolve(),
        ]);
    }
}
