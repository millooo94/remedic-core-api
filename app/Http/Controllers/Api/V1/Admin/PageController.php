<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Pages\StorePageRequest;
use App\Http\Requests\Api\V1\Admin\Pages\UpdatePageRequest;
use App\Http\Requests\Api\V1\Admin\Pages\UploadPageImageRequest;
use App\Http\Resources\Api\V1\Admin\PageResource;
use App\Models\Page;
use App\Models\Section;
use App\Services\PageContentService;
use App\Services\PageSlugRedirectService;
use App\Support\Media\PublicMediaUrl;
use App\Support\Pages\PageSectionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PageController extends Controller
{
    public function __construct(
        private readonly PageSlugRedirectService $pageSlugRedirectService,
        private readonly PageContentService $content,
    ) {}

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = Page::query()->with(['sections', 'faqs']);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        if ($request->filled('publication_state')) {
            $query->publicationState((string) $request->validated('publication_state'));
        }

        $sort = $request->sort();
        $direction = $request->direction();

        match ($sort) {
            'slug' => $query->orderBy('slug', $direction),
            'published_at' => $query->orderBy('published_at', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('title', $direction),
        };

        return PageResource::collection($query->paginate($request->perPage()));
    }

    public function store(StorePageRequest $request): PageResource
    {
        $page = DB::transaction(fn () => $this->persist(new Page, $request->validated()));

        return new PageResource($page->load(['sections', 'faqs']));
    }

    public function show(Page $page): PageResource
    {
        return new PageResource($page->load(['sections', 'faqs']));
    }

    public function update(UpdatePageRequest $request, Page $page): PageResource
    {
        if ($page->isLegacyCheckupPage()) {
            abort(Response::HTTP_CONFLICT, 'La pagina Check-up legacy è protetta e non può essere modificata.');
        }

        $page = DB::transaction(fn () => $this->persist($page, $request->validated()));

        return new PageResource($page->load(['sections', 'faqs']));
    }

    public function destroy(Page $page): Response|JsonResponse
    {
        if ($page->isLegacyCheckupPage()) {
            return response()->json([
                'message' => 'La pagina Check-up legacy è protetta e non può essere eliminata.',
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(fn () => $page->delete());

        return response()->noContent();
    }

    public function uploadMedia(UploadPageImageRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if (isset($validated['page_id'])) {
            return $this->uploadSectionMedia($request, $validated);
        }

        $slot = (string) $validated['slot'];
        $file = $request->file('image');

        $storedPath = $file->store("pages/{$slot}", 'public');

        return response()->json([
            'path' => $storedPath,
            'url' => PublicMediaUrl::fromPublicDisk($storedPath, $request),
            'slot' => $slot,
            'original_name' => $file->getClientOriginalName(),
        ]);
    }

    public function deleteSectionMedia(Page $page, string $sectionKey): JsonResponse
    {
        if (! PageSectionRegistry::supportsMedia($page, $sectionKey)) {
            throw ValidationException::withMessages([
                'section_key' => 'Lo slot media non è consentito per questa sezione.',
            ]);
        }

        $section = $page->sections()->where('key', $sectionKey)->firstOrFail();
        $extra = $section->extra_json ?? [];
        $oldPath = $extra['image_path'] ?? null;
        $extra['image_path'] = null;
        $section->forceFill(['extra_json' => $extra])->save();
        $this->deleteOwnedSectionMediaIfUnused($page, $section, is_string($oldPath) ? $oldPath : null);

        return response()->json([
            'section_key' => $sectionKey,
            'media_slot' => 'image',
            'image_path' => null,
            'image_url' => null,
            'image_alt' => $extra['image_alt'] ?? null,
        ]);
    }

    private function persist(Page $page, array $payload): Page
    {
        $previousSlug = $page->exists ? (string) $page->slug : null;
        $relationsPayload = array_intersect_key($payload, array_flip([
            'sections',
            'removed_section_keys',
            'faqs',
            'removed_faq_ids',
        ]));

        unset($payload['sections'], $payload['removed_section_keys'], $payload['faqs'], $payload['removed_faq_ids']);

        if (! $page->exists && ! array_key_exists('internal_key', $payload)) {
            $payload['internal_key'] = match ($payload['slug'] ?? null) {
                Page::CENTER_SLUG => Page::CENTER_INTERNAL_KEY,
                Page::WHY_CHOOSE_US_SLUG => Page::WHY_CHOOSE_US_INTERNAL_KEY,
                Page::PLUS_HEALTH_PROTOCOL_SLUG => Page::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY,
                Page::CONTACT_SLUG => Page::CONTACT_INTERNAL_KEY,
                Page::CONVENTIONS_NETWORK_SLUG => Page::CONVENTIONS_NETWORK_INTERNAL_KEY,
                Page::CAREERS_SLUG => Page::CAREERS_INTERNAL_KEY,
                Page::TERMS_OF_SERVICE_SLUG => Page::TERMS_OF_SERVICE_INTERNAL_KEY,
                default => (string) ($payload['slug'] ?? ''),
            };
        }

        if (PageSectionRegistry::hasDefinitionsFor((string) ($payload['internal_key'] ?? $page->internal_key))) {
            $payload['faq_enabled'] = false;
        }

        $page->fill($payload);
        $page->save();

        if ($previousSlug !== null) {
            $this->pageSlugRedirectService->sync($page, $previousSlug, (string) $page->slug);
        }

        $this->content->sync($page, $relationsPayload);

        return $page;
    }

    /** @param array<string, mixed> $validated */
    private function uploadSectionMedia(UploadPageImageRequest $request, array $validated): JsonResponse
    {
        $page = Page::query()->findOrFail((int) $validated['page_id']);
        $sectionKey = (string) $validated['section_key'];
        $mediaSlot = (string) $validated['media_slot'];

        if (! PageSectionRegistry::supportsMedia($page, $sectionKey, $mediaSlot)) {
            throw ValidationException::withMessages([
                'section_key' => 'Lo slot media non è consentito per questa sezione.',
            ]);
        }

        $this->content->initializeMissingSections($page);
        $section = $page->sections()->where('key', $sectionKey)->firstOrFail();
        $storedPath = $request->file('image')->store("pages/{$page->id}/{$sectionKey}/{$mediaSlot}", 'public');
        $extra = $section->extra_json ?? [];
        $oldPath = $extra['image_path'] ?? null;
        $extra['image_path'] = $storedPath;
        $section->forceFill(['extra_json' => $extra])->save();
        $this->deleteOwnedSectionMediaIfUnused($page, $section, is_string($oldPath) ? $oldPath : null);

        return response()->json([
            'section_key' => $sectionKey,
            'media_slot' => $mediaSlot,
            'image_path' => $storedPath,
            'image_url' => PublicMediaUrl::fromPublicDisk($storedPath, $request),
            'image_alt' => $extra['image_alt'] ?? null,
            'original_name' => $request->file('image')->getClientOriginalName(),
        ]);
    }

    private function deleteOwnedSectionMediaIfUnused(Page $page, Section $section, ?string $path): void
    {
        if (! $path || ! str_starts_with($path, "pages/{$page->id}/{$section->key}/")) {
            return;
        }

        $stillReferenced = Section::query()
            ->where('id', '!=', $section->id)
            ->where('extra_json->image_path', $path)
            ->exists();

        if (! $stillReferenced) {
            Storage::disk('public')->delete($path);
        }
    }
}
