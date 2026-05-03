<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MarketingSegmentResource;
use App\Models\MarketingSegment;
use App\Services\MarketingCampaignService;
use App\Services\MarketingSegmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MarketingSegmentController extends Controller
{
    public function __construct(
        private readonly MarketingSegmentService $service,
        private readonly MarketingCampaignService $campaignService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $segments = $this->service->baseQuery($filters)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return MarketingSegmentResource::collection($segments);
    }

    public function store(Request $request): MarketingSegmentResource
    {
        $segment = $this->service->create($this->validatedPayload($request), $request->user());

        return new MarketingSegmentResource($segment);
    }

    public function show(MarketingSegment $marketingSegment): MarketingSegmentResource
    {
        return new MarketingSegmentResource($marketingSegment->load(['creator', 'updater', 'manualRecipients.patient'])->loadCount('manualRecipients'));
    }

    public function update(Request $request, MarketingSegment $marketingSegment): MarketingSegmentResource
    {
        $segment = $this->service->update($marketingSegment, $this->validatedPayload($request), $request->user());

        return new MarketingSegmentResource($segment);
    }

    public function destroy(MarketingSegment $marketingSegment): Response
    {
        $this->service->delete($marketingSegment);

        return response()->noContent();
    }

    public function preview(Request $request): array
    {
        $payload = $request->validate([
            'segment_type' => ['nullable', 'in:filter_based,manual'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required_with:filters', 'string', 'max:80'],
            'filters.*.operator' => ['required_with:filters', 'string', 'max:40'],
            'filters.*.value' => ['nullable'],
            'filters.*.display_value' => ['nullable', 'string', 'max:190'],
            'manual_numbers' => ['nullable', 'array'],
            'manual_numbers.*' => ['nullable', 'string', 'max:80'],
        ]);

        $preview = $this->service->previewForPayload($payload);

        return [
            'patients_count' => $preview['count'],
            'invalid_numbers' => $preview['invalid'],
        ];
    }

    public function campaignPreview(Request $request, MarketingSegment $marketingSegment): array
    {
        $payload = $request->validate([
            'channel' => ['required', 'in:sms,whatsapp,email,all'],
        ]);

        return $this->campaignService->previewForSegment($marketingSegment, $payload['channel']);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'segment_type' => ['nullable', 'in:filter_based,manual'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required_with:filters', 'string', 'max:80'],
            'filters.*.operator' => ['required_with:filters', 'string', 'max:40'],
            'filters.*.value' => ['nullable'],
            'filters.*.display_value' => ['nullable', 'string', 'max:190'],
            'manual_numbers' => ['nullable', 'array'],
            'manual_numbers.*' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
