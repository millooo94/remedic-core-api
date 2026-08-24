<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Pages\StorePageRequest;
use App\Http\Requests\Api\V1\Admin\Pages\UpdatePageRequest;
use App\Http\Requests\Api\V1\Admin\Pages\UploadPageImageRequest;
use App\Http\Resources\Api\V1\Admin\PageResource;
use App\Models\Page;
use App\Services\PageContentService;
use App\Services\PageSlugRedirectService;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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
            $payload['internal_key'] = (string) ($payload['slug'] ?? '');
        }

        $page->fill($payload);
        $page->save();

        if ($previousSlug !== null) {
            $this->pageSlugRedirectService->sync($page, $previousSlug, (string) $page->slug);
        }

        $this->content->sync($page, $relationsPayload);

        return $page;
    }
}
