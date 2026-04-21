<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CountingPeriods\StoreCountingPeriodRequest;
use App\Http\Requests\Api\V1\CountingPeriods\UpdateCountingPeriodRequest;
use App\Http\Resources\Api\V1\CountingPeriodResource;
use App\Models\CountingPeriod;
use App\Services\CountingPeriodService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CountingPeriodController extends Controller
{
    public function __construct(
        private readonly CountingPeriodService $service,
    ) {
    }

    public function index(): AnonymousResourceCollection
    {
        return CountingPeriodResource::collection(
            CountingPeriod::query()->orderByDesc('start_date')->get(),
        );
    }

    public function store(StoreCountingPeriodRequest $request): CountingPeriodResource
    {
        $period = CountingPeriod::query()->create($request->validated());

        return new CountingPeriodResource($period);
    }

    public function show(CountingPeriod $countingPeriod): CountingPeriodResource
    {
        return new CountingPeriodResource($countingPeriod);
    }

    public function update(UpdateCountingPeriodRequest $request, CountingPeriod $countingPeriod): CountingPeriodResource
    {
        $countingPeriod->fill($request->validated());
        $countingPeriod->save();

        return new CountingPeriodResource($countingPeriod->refresh());
    }

    public function destroy(CountingPeriod $countingPeriod): Response
    {
        $countingPeriod->delete();

        return response()->noContent();
    }

    public function summary(CountingPeriod $countingPeriod): array
    {
        return $this->service->summary($countingPeriod);
    }

    public function previewSummary(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        return $this->service->summary(null, $validated['start_date'], $validated['end_date']);
    }
}
