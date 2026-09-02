<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicationState;
use App\Enums\ServiceClassification;
use App\Enums\ServicePricingItemKind;
use App\Enums\ServicePricingRecipient;
use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Models\Service;
use App\Models\ServicePricingItem;
use App\Models\ServicePricingProfile;
use App\Models\ServicePricingProfilePresentation;
use App\Services\ManagedMediaService;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Administrative CRUD for the commercial pricing structure and its Web-only presentation. */
class ServiceStructuredPricingController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media) {}

    public function show(Service $service): JsonResponse
    {
        return response()->json(['data' => $this->payload($service)]);
    }

    public function storeProfile(Request $request, Service $service): JsonResponse
    {
        $this->assertPricingCanBeConfigured($service);
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]);
        $profile = $service->pricingProfiles()->create([...$data, 'is_ungrouped' => false, 'sort_order' => (int) $service->pricingProfiles()->where('is_ungrouped', false)->max('sort_order') + 1]);

        return response()->json(['data' => $this->payload($service)], 201);
    }

    public function updateProfile(Request $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        abort_if($profile->is_ungrouped, 422, 'Il contenitore delle tariffe senza area non e modificabile.');
        $profile->update($request->validate(['label' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]));
        if ($profile->presentation) {
            $profile->presentation->translations()->update(['source_revision' => $this->profileRevision($profile, $profile->presentation)]);
        }

        return response()->json(['data' => $this->payload($service)]);
    }

    public function destroyProfile(Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        abort_if($profile->is_ungrouped, 422, 'Il contenitore delle tariffe senza area non e eliminabile.');
        $profile->delete();

        return response()->json(['data' => $this->payload($service)]);
    }

    public function reorderProfiles(Request $request, Service $service): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];
        $this->reorder($service->pricingProfiles()->where('is_ungrouped', false)->get(), $ids, 'aree');

        return response()->json(['data' => $this->payload($service)]);
    }

    public function storeItem(Request $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertPricingCanBeConfigured($service);
        $this->assertProfile($service, $profile);
        $data = $this->itemData($request);
        $profile->items()->create([...$data, 'sort_order' => (int) $profile->items()->max('sort_order') + 1]);

        return response()->json(['data' => $this->payload($service)], 201);
    }

    public function storeFlatItem(Request $request, Service $service): JsonResponse
    {
        $this->assertPricingCanBeConfigured($service);
        $data = $this->itemData($request);

        DB::transaction(function () use ($service, $data): void {
            $lockedService = Service::query()->lockForUpdate()->findOrFail($service->id);
            $profile = $lockedService->pricingProfiles()->where('is_ungrouped', true)->first();
            if ($profile === null) {
                $profile = $lockedService->pricingProfiles()->create([
                    'label' => 'Tariffe',
                    'is_ungrouped' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }

            $nextOrder = (int) $profile->items()->max('sort_order') + 1;
            $profile->items()->create([...$data, 'sort_order' => $nextOrder]);
        });

        return response()->json(['data' => $this->payload($service)], 201);
    }

    public function updateItem(Request $request, Service $service, ServicePricingProfile $profile, ServicePricingItem $item): JsonResponse
    {
        $this->assertItem($service, $profile, $item);
        $item->update($this->itemData($request));
        if ($item->presentation) {
            $item->presentation->translations()->update(['source_revision' => $this->itemRevision($item, $item->presentation)]);
        }

        return response()->json(['data' => $this->payload($service)]);
    }

    public function destroyItem(Service $service, ServicePricingProfile $profile, ServicePricingItem $item): JsonResponse
    {
        $this->assertItem($service, $profile, $item);
        $item->delete();

        return response()->json(['data' => $this->payload($service)]);
    }

    public function reorderItems(Request $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];
        $this->reorder($profile->items()->get(), $ids, 'items');

        return response()->json(['data' => $this->payload($service)]);
    }

    public function reorderFlatItems(Request $request, Service $service): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];
        $items = ServicePricingItem::query()
            ->whereHas('profile', fn ($query) => $query->where('service_id', $service->id)->where('is_ungrouped', true))
            ->get();
        $this->reorder($items, $ids, 'items');

        return response()->json(['data' => $this->payload($service)]);
    }

    public function updateProfilePresentation(Request $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        $data = $request->validate(['public_label' => ['nullable', 'string', 'max:255'], 'intro' => ['nullable', 'string'], 'is_web_enabled' => ['required', 'boolean'], 'translations' => ['present', 'array'], 'translations.*.locale' => ['required', Rule::enum(SupportedLocale::class)], 'translations.*.label' => ['nullable', 'string', 'max:255'], 'translations.*.note' => ['prohibited'], 'translations.*.publication_state' => ['required', Rule::enum(PublicationState::class)]]);
        $presentation = $profile->presentation()->firstOrNew();
        $presentation->fill($data);
        $presentation->save();
        $this->syncTranslations($presentation, $data['translations'], $this->profileRevision($profile, $presentation));

        return response()->json(['data' => $this->payload($service)]);
    }

    public function uploadProfileImage(UploadMasterImageRequest $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        abort_if($profile->is_ungrouped, 422, 'Il contenitore delle tariffe senza area non puo avere un’immagine.');
        $this->media->replace($profile, 'image_path', $request->file('image'), "service-pricing/{$profile->id}/images");

        return response()->json(['data' => $this->payload($service)]);
    }

    public function deleteProfileImage(Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        abort_if($profile->is_ungrouped, 422, 'Il contenitore delle tariffe senza area non puo avere un’immagine.');
        $this->media->delete($profile, 'image_path', ["service-pricing/{$profile->id}/images"]);

        return response()->json(['data' => $this->payload($service)]);
    }

    public function updateItemPresentation(Request $request, Service $service, ServicePricingProfile $profile, ServicePricingItem $item): JsonResponse
    {
        $this->assertItem($service, $profile, $item);
        $data = $request->validate(['icon_path' => ['nullable', 'string', 'max:1024'], 'public_label' => ['nullable', 'string', 'max:255'], 'public_note' => ['nullable', 'string'], 'is_highlighted' => ['required', 'boolean'], 'is_web_enabled' => ['required', 'boolean'], 'translations' => ['present', 'array'], 'translations.*.locale' => ['required', Rule::enum(SupportedLocale::class)], 'translations.*.label' => ['nullable', 'string', 'max:255'], 'translations.*.note' => ['nullable', 'string'], 'translations.*.publication_state' => ['required', Rule::enum(PublicationState::class)]]);
        $presentation = $item->presentation()->firstOrNew();
        $presentation->fill($data);
        $presentation->save();
        $this->syncTranslations($presentation, $data['translations'], $this->itemRevision($item, $presentation));

        return response()->json(['data' => $this->payload($service)]);
    }

    public function uploadItemIcon(Request $request, Service $service, ServicePricingProfile $profile, ServicePricingItem $item): JsonResponse
    {
        $this->assertItem($service, $profile, $item);
        $request->validate(['icon' => ['required', 'file', 'mimetypes:image/svg+xml,image/png,image/webp', 'max:2048']]);
        $presentation = $item->presentation()->firstOrCreate();
        $this->media->replace($presentation, 'icon_path', $request->file('icon'), "service-pricing/{$item->id}/icons");

        return response()->json(['data' => $this->payload($service)]);
    }

    public function deleteItemIcon(Service $service, ServicePricingProfile $profile, ServicePricingItem $item): JsonResponse
    {
        $this->assertItem($service, $profile, $item);
        if ($item->presentation) {
            $this->media->delete($item->presentation, 'icon_path', ["service-pricing/{$item->id}/icons"]);
        }

        return response()->json(['data' => $this->payload($service)]);
    }

    private function itemData(Request $request): array
    {
        return $request->validate(['label' => ['required', 'string', 'max:255'], 'kind' => ['required', Rule::enum(ServicePricingItemKind::class)], 'recipient' => ['required', Rule::enum(ServicePricingRecipient::class)], 'price_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:99999999.99'], 'business_note' => ['nullable', 'string'], 'is_active' => ['required', 'boolean']]);
    }

    private function assertProfile(Service $service, ServicePricingProfile $profile): void
    {
        abort_unless($profile->service_id === $service->id, 404);
    }

    private function assertPricingCanBeConfigured(Service $service): void
    {
        if ($service->classification === ServiceClassification::AestheticMedicine) {
            return;
        }

        throw ValidationException::withMessages([
            'classification' => ['Il tariffario articolato è disponibile solo per le prestazioni di Medicina estetica.'],
        ]);
    }

    private function assertItem(Service $service, ServicePricingProfile $profile, ServicePricingItem $item): void
    {
        $this->assertProfile($service, $profile);
        abort_unless($item->service_pricing_profile_id === $profile->id, 404);
    }

    private function reorder($models, array $ids, string $key): void
    {
        abort_unless($models->count() === count($ids) && $models->pluck('id')->sort()->values()->all() === collect($ids)->sort()->values()->all(), 422, "Gli identificativi {$key} non appartengono tutti alla stessa Prestazione.");
        DB::transaction(fn () => collect($ids)->each(fn ($id, $order) => $models->firstWhere('id', $id)->update(['sort_order' => $order])));
    }

    private function syncTranslations($presentation, array $translations, string $revision): void
    {
        foreach ($translations as $translation) {
            $presentation->translations()->updateOrCreate(['locale' => $translation['locale']], [...$translation, 'source_revision' => $revision, 'reviewed_source_revision' => $translation['locale'] === 'it' ? $revision : ($translation['publication_state'] === PublicationState::Published->value ? $revision : null)]);
        }
    }

    private function profileRevision(ServicePricingProfile $profile, ServicePricingProfilePresentation $presentation): string
    {
        return hash('sha256', json_encode([$profile->label, $presentation->public_label, $presentation->intro], JSON_THROW_ON_ERROR));
    }

    private function itemRevision(ServicePricingItem $item, $presentation): string
    {
        return hash('sha256', json_encode([$item->label, $presentation->public_label, $presentation->public_note], JSON_THROW_ON_ERROR));
    }

    private function payload(Service $service): array
    {
        return $service->refresh()->load('pricingProfiles.items.presentation.translations', 'pricingProfiles.presentation.translations')->pricingProfiles->map(fn ($profile) => ['id' => $profile->id, 'label' => $profile->label, 'image_path' => $profile->image_path, 'image_url' => PublicMediaUrl::fromPublicDisk($profile->image_path, request()), 'is_ungrouped' => $profile->is_ungrouped, 'is_active' => $profile->is_active, 'sort_order' => $profile->sort_order, 'items' => $profile->items->map(fn ($item) => ['id' => $item->id, 'label' => $item->label, 'kind' => $item->kind->value, 'recipient' => $item->recipient->value, 'price_amount' => $item->price_amount, 'business_note' => $item->business_note, 'is_active' => $item->is_active, 'sort_order' => $item->sort_order, 'presentation' => $item->presentation ? ['icon_path' => $item->presentation->icon_path, 'public_label' => $item->presentation->public_label, 'public_note' => $item->presentation->public_note, 'is_highlighted' => $item->presentation->is_highlighted, 'is_web_enabled' => $item->presentation->is_web_enabled, 'translations' => $item->presentation->translations] : null])->values(), 'presentation' => $profile->presentation ? ['public_label' => $profile->presentation->public_label, 'intro' => $profile->presentation->intro, 'is_web_enabled' => $profile->presentation->is_web_enabled, 'translations' => $profile->presentation->translations] : null])->values()->all();
    }
}
