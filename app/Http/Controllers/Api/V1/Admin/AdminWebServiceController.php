<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\PersistsSectionsAndFaqs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Services\StoreAdminWebServiceRequest;
use App\Http\Requests\Api\V1\Admin\Services\UpdateAdminWebServiceRequest;
use App\Http\Resources\Api\V1\Admin\AdminWebServiceResource;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AdminWebServiceController extends Controller
{
    use PersistsSectionsAndFaqs;

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = Service::query()->with(['sections', 'faqs', 'category']);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('display_name', 'like', "%{$search}%")
                    ->orWhere('canonical_name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_web_active')) {
            $query->where('is_web_active', (bool) $request->boolean('is_web_active'));
        }

        $sort = $request->sort();
        $direction = $request->direction();

        match ($sort) {
            'canonical_name' => $query->orderBy('canonical_name', $direction),
            'slug' => $query->orderBy('slug', $direction),
            'sort_order' => $query->orderBy('sort_order', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('display_name', $direction),
        };

        return AdminWebServiceResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreAdminWebServiceRequest $request): AdminWebServiceResource
    {
        $service = DB::transaction(fn () => $this->persist(new Service(), $request->validated()));

        return new AdminWebServiceResource($service->load(['sections', 'faqs', 'category']));
    }

    public function show(Service $service): AdminWebServiceResource
    {
        return new AdminWebServiceResource($service->load(['sections', 'faqs', 'category']));
    }

    public function update(UpdateAdminWebServiceRequest $request, Service $service): AdminWebServiceResource
    {
        $service = DB::transaction(fn () => $this->persist($service, $request->validated()));

        return new AdminWebServiceResource($service->load(['sections', 'faqs', 'category']));
    }

    public function destroy(Service $service): Response
    {
        $service->delete();

        return response()->noContent();
    }

    private function persist(Service $service, array $payload): Service
    {
        $relationsPayload = [
            'sections' => $payload['sections'] ?? [],
            'faqs' => $payload['faqs'] ?? [],
        ];

        unset($payload['sections'], $payload['faqs']);

        $service->fill($payload);
        $service->save();

        $this->persistSectionsAndFaqs($service, $relationsPayload);

        return $service;
    }
}
