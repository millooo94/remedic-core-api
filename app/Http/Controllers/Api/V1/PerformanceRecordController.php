<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PerformanceRecords\PerformanceRecordQueryRequest;
use App\Http\Requests\Api\V1\PerformanceRecords\StorePerformanceRecordRequest;
use App\Http\Requests\Api\V1\PerformanceRecords\UpdatePerformanceRecordRequest;
use App\Http\Resources\Api\V1\PerformanceRecordResource;
use App\Models\PerformanceRecord;
use App\Services\PerformanceRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PerformanceRecordController extends Controller
{
    public function __construct(
        private readonly PerformanceRecordService $service,
    ) {
    }

    public function index(PerformanceRecordQueryRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $records = $this->service->baseQuery($filters)->paginate($perPage)->withQueryString();
        $payload = PerformanceRecordResource::collection($records)->response()->getData(true);
        $payload['totals'] = $this->service->filteredTotals($filters);

        return response()->json($payload);
    }

    public function store(StorePerformanceRecordRequest $request): PerformanceRecordResource
    {
        $record = $this->service->create($request->validated(), $request->user());

        return new PerformanceRecordResource($record);
    }

    public function show(PerformanceRecord $performanceRecord): PerformanceRecordResource
    {
        return new PerformanceRecordResource($performanceRecord->load(['patient', 'patients', 'professional', 'service.category', 'splits.professional']));
    }

    public function update(UpdatePerformanceRecordRequest $request, PerformanceRecord $performanceRecord): PerformanceRecordResource
    {
        $record = $this->service->update($performanceRecord, $request->validated(), $request->user());

        return new PerformanceRecordResource($record);
    }

    public function destroy(Request $request, PerformanceRecord $performanceRecord): Response
    {
        $this->service->delete($performanceRecord, $request->user());

        return response()->noContent();
    }
}
