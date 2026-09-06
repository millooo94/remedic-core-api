<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\NewsletterCampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NewsletterCampaignResource;
use App\Models\NewsletterCampaign;
use App\Services\NewsletterCampaignService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class NewsletterCampaignController extends Controller
{
    public function __construct(private readonly NewsletterCampaignService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'status' => ['nullable', Rule::enum(NewsletterCampaignStatus::class)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return NewsletterCampaignResource::collection(
            $this->service->baseQuery($filters)->paginate((int) ($filters['per_page'] ?? 20))->withQueryString(),
        );
    }

    public function store(Request $request): NewsletterCampaignResource
    {
        return new NewsletterCampaignResource($this->service->create($this->validatedPayload($request), $request->user()));
    }

    public function show(NewsletterCampaign $newsletterCampaign): NewsletterCampaignResource
    {
        return new NewsletterCampaignResource($newsletterCampaign->load(['creator', 'updater', 'launcher', 'deliveries.subscriber']));
    }

    public function update(Request $request, NewsletterCampaign $newsletterCampaign): NewsletterCampaignResource
    {
        return new NewsletterCampaignResource($this->service->update($newsletterCampaign, $this->validatedPayload($request), $request->user()));
    }

    public function destroy(NewsletterCampaign $newsletterCampaign): Response
    {
        $this->service->delete($newsletterCampaign);

        return response()->noContent();
    }

    public function sendTest(Request $request, NewsletterCampaign $newsletterCampaign): NewsletterCampaignResource
    {
        $payload = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);
        $this->service->sendTest($newsletterCampaign, $payload['email'], $request->user());

        return new NewsletterCampaignResource($newsletterCampaign->refresh()->load(['creator', 'updater', 'launcher']));
    }

    public function sendNow(Request $request, NewsletterCampaign $newsletterCampaign): NewsletterCampaignResource
    {
        return new NewsletterCampaignResource($this->service->sendNow($newsletterCampaign, $request->user()));
    }

    public function schedule(Request $request, NewsletterCampaign $newsletterCampaign): NewsletterCampaignResource
    {
        $payload = $request->validate(['scheduled_at' => ['required', 'date']]);

        return new NewsletterCampaignResource($this->service->schedule($newsletterCampaign, $payload['scheduled_at'], $request->user()));
    }

    public function cancelSchedule(Request $request, NewsletterCampaign $newsletterCampaign): NewsletterCampaignResource
    {
        return new NewsletterCampaignResource($this->service->cancelSchedule($newsletterCampaign, $request->user()));
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'internal_name' => ['required', 'string', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'preheader' => ['nullable', 'string', 'max:190'],
            'content' => ['required', 'string', 'max:10000'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
    }
}
