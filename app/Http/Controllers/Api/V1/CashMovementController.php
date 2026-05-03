<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CashMovements\CashMovementQueryRequest;
use App\Http\Requests\Api\V1\CashMovements\StoreCashMovementRequest;
use App\Http\Requests\Api\V1\CashMovements\UpdateCashMovementRequest;
use App\Http\Resources\Api\V1\CashMovementResource;
use App\Models\CashMovement;
use App\Services\CashMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CashMovementController extends Controller
{
    public function __construct(
        private readonly CashMovementService $service,
    ) {
    }

    public function index(CashMovementQueryRequest $request)
    {
        $filters = $request->filters();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $records = $this->service->baseQuery($filters)->paginate($perPage)->withQueryString();

        return CashMovementResource::collection($records);
    }

    public function summary(CashMovementQueryRequest $request): JsonResponse
    {
        return response()->json($this->service->summary($request->filters()));
    }

    public function reset(Request $request): JsonResponse
    {
        return response()->json([
            'deleted_count' => $this->service->reset($request->user()),
        ]);
    }

    public function store(StoreCashMovementRequest $request): JsonResponse
    {
        $movement = $this->service->create($request->validated(), $request->user());

        return (new CashMovementResource($movement))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(CashMovement $cashMovement): CashMovementResource
    {
        return new CashMovementResource($cashMovement);
    }

    public function update(UpdateCashMovementRequest $request, CashMovement $cashMovement): CashMovementResource
    {
        $movement = $this->service->update($cashMovement, $request->validated(), $request->user());

        return new CashMovementResource($movement);
    }

    public function destroy(Request $request, CashMovement $cashMovement): Response
    {
        $this->service->delete($cashMovement, $request->user());

        return response()->noContent();
    }
}
