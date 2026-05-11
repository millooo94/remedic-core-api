<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\ConsentPolicyVersions\StoreConsentPolicyVersionRequest;
use App\Http\Requests\Api\V1\Admin\ConsentPolicyVersions\UpdateConsentPolicyVersionRequest;
use App\Http\Resources\Api\V1\Admin\ConsentPolicyVersionResource;
use App\Models\ConsentPolicyVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ConsentPolicyVersionController extends Controller
{
    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = ConsentPolicyVersion::query()->with(['policyPage', 'cookiePolicyPage', 'privacyPolicyPage']);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('version', 'like', "%{$search}%")
                    ->orWhere('banner_title', 'like', "%{$search}%")
                    ->orWhere('preferences_title', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        match ($request->sort()) {
            'version' => $query->orderBy('version', $request->direction()),
            'updated_at' => $query->orderBy('updated_at', $request->direction()),
            default => $query->orderBy('published_at', $request->direction()),
        };

        return ConsentPolicyVersionResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreConsentPolicyVersionRequest $request): JsonResponse
    {
        $version = ConsentPolicyVersion::query()->create($request->validated());

        return (new ConsentPolicyVersionResource($version->load(['policyPage', 'cookiePolicyPage', 'privacyPolicyPage'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ConsentPolicyVersion $consentPolicyVersion): ConsentPolicyVersionResource
    {
        return new ConsentPolicyVersionResource($consentPolicyVersion->load(['policyPage', 'cookiePolicyPage', 'privacyPolicyPage']));
    }

    public function update(UpdateConsentPolicyVersionRequest $request, ConsentPolicyVersion $consentPolicyVersion): ConsentPolicyVersionResource
    {
        $consentPolicyVersion->update($request->validated());

        return new ConsentPolicyVersionResource($consentPolicyVersion->fresh()->load(['policyPage', 'cookiePolicyPage', 'privacyPolicyPage']));
    }

    public function destroy(ConsentPolicyVersion $consentPolicyVersion): Response
    {
        $consentPolicyVersion->delete();

        return response()->noContent();
    }
}
