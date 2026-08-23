<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Checkups\StoreCheckupRequest;
use App\Http\Requests\Api\V1\Checkups\UpdateCheckupRequest;
use App\Http\Resources\Api\V1\CheckupResource;
use App\Models\Checkup;
use App\Services\CheckupCatalogService;
use App\Support\Filters\CheckupFilters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CheckupController extends Controller
{
    public function __construct(
        private readonly CheckupCatalogService $service,
        private readonly CheckupFilters $filters,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'search' => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
            'specialization_name' => ['nullable', 'string', 'max:190'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);
        $query = Checkup::query();
        $this->filters->apply($query, $filters);

        $checkups = $query
            ->orderBy('display_name')
            ->get()
            ->map(fn (Checkup $checkup): Checkup => $this->service->loadForResource($checkup));

        return CheckupResource::collection($checkups);
    }

    public function store(StoreCheckupRequest $request): CheckupResource
    {
        $checkup = $this->service->create($request->validated());

        return new CheckupResource($this->service->loadForResource($checkup, true));
    }

    public function show(Checkup $checkup): CheckupResource
    {
        return new CheckupResource($this->service->loadForResource($checkup, true));
    }

    public function update(UpdateCheckupRequest $request, Checkup $checkup): CheckupResource
    {
        $checkup = $this->service->update($checkup, $request->validated());

        return new CheckupResource($this->service->loadForResource($checkup, true));
    }

    public function destroy(Checkup $checkup): Response
    {
        $this->service->delete($checkup);

        return response()->noContent();
    }
}
