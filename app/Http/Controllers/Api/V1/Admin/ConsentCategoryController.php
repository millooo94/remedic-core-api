<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ConsentCategoryKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\ConsentCategories\StoreConsentCategoryRequest;
use App\Http\Requests\Api\V1\Admin\ConsentCategories\UpdateConsentCategoryRequest;
use App\Http\Resources\Api\V1\Admin\ConsentCategoryResource;
use App\Models\ConsentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ConsentCategoryController extends Controller
{
    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = ConsentCategory::query()->withCount('services');

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('key', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        match ($request->sort()) {
            'key' => $query->orderBy('key', $request->direction()),
            'updated_at' => $query->orderBy('updated_at', $request->direction()),
            default => $query->orderBy('sort_order', $request->direction())->orderBy('id'),
        };

        return ConsentCategoryResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreConsentCategoryRequest $request): JsonResponse
    {
        $category = ConsentCategory::query()->create($request->validated());

        return (new ConsentCategoryResource($category->loadCount('services')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ConsentCategory $consentCategory): ConsentCategoryResource
    {
        return new ConsentCategoryResource($consentCategory->loadCount('services'));
    }

    public function update(UpdateConsentCategoryRequest $request, ConsentCategory $consentCategory): ConsentCategoryResource
    {
        $payload = $request->validated();

        if ($consentCategory->key === ConsentCategoryKey::NECESSARY->value) {
            $payload['key'] = ConsentCategoryKey::NECESSARY->value;
            $payload['default_state'] = true;
            $payload['is_required'] = true;
            $payload['is_active'] = true;
        }

        $consentCategory->update($payload);

        return new ConsentCategoryResource($consentCategory->fresh()->loadCount('services'));
    }

    public function destroy(ConsentCategory $consentCategory): Response
    {
        if ($consentCategory->key === ConsentCategoryKey::NECESSARY->value) {
            abort(422, 'La categoria necessaria non puo essere eliminata.');
        }

        $consentCategory->delete();

        return response()->noContent();
    }
}
