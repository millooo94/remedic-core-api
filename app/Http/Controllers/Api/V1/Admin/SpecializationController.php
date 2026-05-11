<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\PersistsSectionsAndFaqs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Specializations\StoreSpecializationRequest;
use App\Http\Requests\Api\V1\Admin\Specializations\UpdateSpecializationRequest;
use App\Http\Resources\Api\V1\Admin\SpecializationResource;
use App\Models\Specialization;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SpecializationController extends Controller
{
    use PersistsSectionsAndFaqs;

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = Specialization::query()
            ->with(['sections', 'faqs'])
            ->withCount(['services', 'professionals']);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")
                    ->orWhere('local_seo_title', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        $sort = $request->sort();
        $direction = $request->direction();

        match ($sort) {
            'slug' => $query->orderBy('slug', $direction),
            'sort_order' => $query->orderBy('sort_order', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('name', $direction),
        };

        return SpecializationResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreSpecializationRequest $request): SpecializationResource
    {
        $specialization = DB::transaction(fn () => $this->persist(new Specialization(), $request->validated()));

        return new SpecializationResource($specialization->load(['sections', 'faqs'])->loadCount(['services', 'professionals']));
    }

    public function show(Specialization $specialization): SpecializationResource
    {
        return new SpecializationResource($specialization->load(['sections', 'faqs'])->loadCount(['services', 'professionals']));
    }

    public function update(UpdateSpecializationRequest $request, Specialization $specialization): SpecializationResource
    {
        $specialization = DB::transaction(fn () => $this->persist($specialization, $request->validated()));

        return new SpecializationResource($specialization->load(['sections', 'faqs'])->loadCount(['services', 'professionals']));
    }

    public function destroy(Specialization $specialization): Response
    {
        $specialization->delete();

        return response()->noContent();
    }

    private function persist(Specialization $specialization, array $payload): Specialization
    {
        $relationsPayload = [
            'sections' => $payload['sections'] ?? [],
            'faqs' => $payload['faqs'] ?? [],
        ];

        unset($payload['sections'], $payload['faqs']);

        $specialization->fill($payload);
        $specialization->save();

        $this->persistSectionsAndFaqs($specialization, $relationsPayload);

        return $specialization;
    }
}
