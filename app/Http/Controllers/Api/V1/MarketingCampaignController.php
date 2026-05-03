<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MarketingCampaignDeliveryResource;
use App\Http\Resources\Api\V1\MarketingCampaignResource;
use App\Models\MarketingCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Validator;

class MarketingCampaignController extends Controller
{
    public function __construct(
        private readonly MarketingCampaignService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->normalizeBooleanQuery($request, ['history_only']);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'channel' => ['nullable', 'in:sms,whatsapp,email,all'],
            'status' => ['nullable', 'in:draft,scheduled,queued,sending,sent,partial_failed,failed'],
            'history_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $campaigns = $this->service->baseQuery($filters)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return MarketingCampaignResource::collection($campaigns);
    }

    public function store(Request $request): MarketingCampaignResource
    {
        $campaign = $this->service->create($this->validatedPayload($request), $request->user());

        return new MarketingCampaignResource($campaign);
    }

    public function show(MarketingCampaign $marketingCampaign): MarketingCampaignResource
    {
        return new MarketingCampaignResource(
            $marketingCampaign->load(['segment', 'creator', 'launcher', 'deliveries.patient'])
        );
    }

    public function update(Request $request, MarketingCampaign $marketingCampaign): MarketingCampaignResource
    {
        $campaign = $this->service->update($marketingCampaign, $this->validatedPayload($request), $request->user());

        return new MarketingCampaignResource($campaign);
    }

    public function destroy(MarketingCampaign $marketingCampaign): Response
    {
        $this->service->delete($marketingCampaign);

        return response()->noContent();
    }

    public function sendTest(Request $request, MarketingCampaign $marketingCampaign): MarketingCampaignDeliveryResource
    {
        $payload = $request->validate([
            'target' => ['required', 'string', 'max:190'],
        ]);

        $delivery = $this->service->sendTest($marketingCampaign, $payload['target'], $request->user());

        return new MarketingCampaignDeliveryResource($delivery);
    }

    public function launch(Request $request, MarketingCampaign $marketingCampaign): MarketingCampaignResource
    {
        $payload = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $campaign = $this->service->launch($marketingCampaign, $request->user(), $payload['scheduled_at'] ?? null);

        return new MarketingCampaignResource($campaign);
    }

    private function validatedPayload(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'marketing_segment_id' => ['required', 'integer', 'exists:marketing_segments,id'],
            'channel' => ['required', 'in:sms,whatsapp,email,all'],
            'template_key' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'remove_whatsapp_image' => ['nullable', 'boolean'],
            'whatsapp_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($request->hasFile('whatsapp_image')) {
            /** @var UploadedFile $uploadedFile */
            $uploadedFile = $request->file('whatsapp_image');
            $payload['whatsapp_image'] = $uploadedFile;
        }

        /** @var Validator $validator */
        $validator = validator($payload, []);
        $validator->after(function (Validator $validator) use ($payload): void {
            if (in_array(($payload['channel'] ?? null), ['email', 'all'], true) && blank($payload['subject'] ?? null)) {
                $validator->errors()->add('subject', 'Per campagne email o tutti i canali l\'oggetto e obbligatorio.');
            }

            if (! in_array(($payload['channel'] ?? null), ['whatsapp', 'all'], true) && array_key_exists('whatsapp_image', $payload)) {
                $validator->errors()->add('whatsapp_image', 'L\'immagine WhatsApp puo essere caricata solo se il canale include WhatsApp.');
            }
        });
        $validator->validate();

        return $payload;
    }

    private function normalizeBooleanQuery(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $normalized = filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                $request->merge([$key => $normalized]);
            }
        }
    }
}
