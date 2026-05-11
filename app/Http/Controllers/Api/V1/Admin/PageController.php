<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\PersistsSectionsAndFaqs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Pages\StorePageRequest;
use App\Http\Requests\Api\V1\Admin\Pages\UpdatePageRequest;
use App\Http\Resources\Api\V1\Admin\PageResource;
use App\Models\Page;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PageController extends Controller
{
    use PersistsSectionsAndFaqs;

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
        $page = DB::transaction(fn () => $this->persist(new Page(), $request->validated()));

        return new PageResource($page->load(['sections', 'faqs']));
    }

    public function show(Page $page): PageResource
    {
        return new PageResource($page->load(['sections', 'faqs']));
    }

    public function update(UpdatePageRequest $request, Page $page): PageResource
    {
        $page = DB::transaction(fn () => $this->persist($page, $request->validated()));

        return new PageResource($page->load(['sections', 'faqs']));
    }

    public function destroy(Page $page): Response
    {
        $page->delete();

        return response()->noContent();
    }

    private function persist(Page $page, array $payload): Page
    {
        $relationsPayload = [
            'sections' => $payload['sections'] ?? [],
            'faqs' => $payload['faqs'] ?? [],
        ];

        unset($payload['sections'], $payload['faqs']);

        $page->fill($payload);
        $page->save();

        $this->persistSectionsAndFaqs($page, $relationsPayload);

        return $page;
    }
}
