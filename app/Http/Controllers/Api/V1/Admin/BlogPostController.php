<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\PersistsSectionsAndFaqs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\BlogPosts\StoreBlogPostRequest;
use App\Http\Requests\Api\V1\Admin\BlogPosts\UpdateBlogPostRequest;
use App\Http\Resources\Api\V1\Admin\BlogPostResource;
use App\Models\BlogPost;
use App\Models\Redirect;
use App\Services\BlogPostSlugRedirectService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BlogPostController extends Controller
{
    use PersistsSectionsAndFaqs;

    public function __construct(private readonly BlogPostSlugRedirectService $redirects) {}

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = BlogPost::query()->with($this->relations());

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

        return BlogPostResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreBlogPostRequest $request): BlogPostResource
    {
        $post = DB::transaction(fn () => $this->persist(new BlogPost, $request->validated()));

        return new BlogPostResource($post->load($this->relations()));
    }

    public function show(BlogPost $blogPost): BlogPostResource
    {
        return new BlogPostResource($blogPost->load($this->relations()));
    }

    public function update(UpdateBlogPostRequest $request, BlogPost $blogPost): BlogPostResource
    {
        $post = DB::transaction(fn () => $this->persist($blogPost, $request->validated()));

        return new BlogPostResource($post->load($this->relations()));
    }

    public function destroy(BlogPost $blogPost): Response
    {
        DB::transaction(function () use ($blogPost): void {
            Redirect::query()->automatic()
                ->where('source_type', Redirect::SOURCE_TYPE_BLOG_POST)
                ->where('source_id', $blogPost->id)
                ->delete();
            $blogPost->delete();
        });

        return response()->noContent();
    }

    private function persist(BlogPost $blogPost, array $payload): BlogPost
    {
        $previousSlug = $blogPost->exists ? $blogPost->slug : null;
        $relationsPayload = [
            'sections' => $payload['sections'] ?? [],
            'faqs' => $payload['faqs'] ?? [],
        ];
        $syncRelatedServices = array_key_exists('related_service_ids', $payload);
        $syncRelatedArticles = array_key_exists('related_article_ids', $payload);
        $relatedServiceIds = $payload['related_service_ids'] ?? [];
        $relatedArticleIds = $payload['related_article_ids'] ?? [];

        unset($payload['sections'], $payload['faqs'], $payload['related_service_ids'], $payload['related_article_ids']);

        $blogPost->fill($payload);
        $blogPost->save();

        $this->persistSectionsAndFaqs($blogPost, $relationsPayload);
        if ($syncRelatedServices) {
            $blogPost->relatedServices()->sync($this->pivotPayload($relatedServiceIds));
        }
        if ($syncRelatedArticles) {
            $blogPost->relatedArticles()->sync($this->pivotPayload($relatedArticleIds));
        }

        if ($previousSlug !== null && $previousSlug !== $blogPost->slug) {
            $this->redirects->sync($blogPost, $previousSlug, $blogPost->slug);
        }

        return $blogPost;
    }

    private function relations(): array
    {
        return ['sections', 'faqs', 'relatedServices', 'relatedArticles'];
    }

    private function pivotPayload(array $ids): array
    {
        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->mapWithKeys(fn (int $id, int $index): array => [$id => ['sort_order' => $index]])
            ->all();
    }
}
