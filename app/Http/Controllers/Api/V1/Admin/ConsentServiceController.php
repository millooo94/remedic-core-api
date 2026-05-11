<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ConsentServices\ConsentServiceIndexRequest;
use App\Http\Requests\Api\V1\Admin\ConsentServices\StoreConsentServiceRequest;
use App\Http\Requests\Api\V1\Admin\ConsentServices\UpdateConsentServiceRequest;
use App\Http\Resources\Api\V1\Admin\ConsentServiceResource;
use App\Models\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ConsentServiceController extends Controller
{
    public function index(ConsentServiceIndexRequest $request): AnonymousResourceCollection
    {
        $query = ConsentService::query()->with('category');

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('key', 'like', "%{$search}%")
                    ->orWhere('provider', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        if ($request->validated('consent_category_id')) {
            $query->where('consent_category_id', $request->integer('consent_category_id'));
        }

        match ($request->sort()) {
            'provider' => $query->orderBy('provider', $request->direction()),
            'execution_mode' => $query->orderBy('execution_mode', $request->direction()),
            'updated_at' => $query->orderBy('updated_at', $request->direction()),
            default => $query->orderBy('sort_order', $request->direction())->orderBy('id'),
        };

        return ConsentServiceResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreConsentServiceRequest $request): JsonResponse
    {
        $service = ConsentService::query()->create($request->validated());

        return (new ConsentServiceResource($service->load('category')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ConsentService $consentService): ConsentServiceResource
    {
        return new ConsentServiceResource($consentService->load('category'));
    }

    public function update(UpdateConsentServiceRequest $request, ConsentService $consentService): ConsentServiceResource
    {
        $consentService->update($request->validated());

        return new ConsentServiceResource($consentService->fresh()->load('category'));
    }

    public function destroy(ConsentService $consentService): Response
    {
        $consentService->delete();

        return response()->noContent();
    }
}
