<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicationState;
use App\Enums\ServicePricingItemKind;
use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServicePricingItem;
use App\Models\ServicePricingProfile;
use App\Models\ServicePricingProfilePresentation;
use App\Services\ManagedMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $data = $request->validate(['label' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]);
        $profile = $service->pricingProfiles()->create([...$data, 'sort_order' => (int) $service->pricingProfiles()->max('sort_order') + 1]);

        return response()->json(['data' => $this->payload($service)], 201);
    }

    public function updateProfile(Request $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        $profile->update($request->validate(['label' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]));
        if ($profile->presentation) {
            $profile->presentation->translations()->update(['source_revision' => $this->profileRevision($profile, $profile->presentation)]);
        }

        return response()->json(['data' => $this->payload($service)]);
    }

    public function destroyProfile(Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        $profile->delete();

        return response()->json(['data' => $this->payload($service)]);
    }

    public function reorderProfiles(Request $request, Service $service): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];
        $this->reorder($service->pricingProfiles()->get(), $ids, 'profiles');

        return response()->json(['data' => $this->payload($service)]);
    }

    public function storeItem(Request $request, Service $service, ServicePricingProfile $profile): JsonResponse
    {
        $this->assertProfile($service, $profile);
        $data = $this->itemData($request);
        $profile->items()->create([...$data, 'sort_order' => (int) $profile->items()->max('sort_order') + 1]);

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
        return $request->validate(['label' => ['required', 'string', 'max:255'], 'kind' => ['required', Rule::enum(ServicePricingItemKind::class)], 'price_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:99999999.99'], 'business_note' => ['nullable', 'string'], 'is_active' => ['required', 'boolean']]);
    }

    private function assertProfile(Service $service, ServicePricingProfile $profile): void
    {
        abort_unless($profile->service_id === $service->id, 404);
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
        return $service->refresh()->load('pricingProfiles.items.presentation.translations', 'pricingProfiles.presentation.translations')->pricingProfiles->map(fn ($profile) => ['id' => $profile->id, 'label' => $profile->label, 'is_active' => $profile->is_active, 'items' => $profile->items->map(fn ($item) => ['id' => $item->id, 'label' => $item->label, 'kind' => $item->kind->value, 'price_amount' => $item->price_amount, 'business_note' => $item->business_note, 'is_active' => $item->is_active, 'presentation' => $item->presentation ? ['icon_path' => $item->presentation->icon_path, 'public_label' => $item->presentation->public_label, 'public_note' => $item->presentation->public_note, 'is_highlighted' => $item->presentation->is_highlighted, 'is_web_enabled' => $item->presentation->is_web_enabled, 'translations' => $item->presentation->translations] : null])->values(), 'presentation' => $profile->presentation ? ['public_label' => $profile->presentation->public_label, 'intro' => $profile->presentation->intro, 'is_web_enabled' => $profile->presentation->is_web_enabled, 'translations' => $profile->presentation->translations] : null])->values()->all();
    }
}
