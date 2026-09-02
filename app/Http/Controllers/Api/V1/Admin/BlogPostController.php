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
use App\Models\Section;
use App\Services\BlogPostSlugRedirectService;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        if ($request->filled('content_type')) {
            $query->where('content_type', $request->validated('content_type'));
        }

        if ($request->filled('editorial_category_id')) {
            $query->where('editorial_category_id', $request->validated('editorial_category_id'));
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

    public function uploadSectionMedia(Request $request, BlogPost $blogPost, Section $section): JsonResponse
    {
        abort_unless($section->sectionable_type === BlogPost::class && $section->sectionable_id === $blogPost->id, 404);
        if (($section->template?->value ?? $section->template) !== 'image_text') {
            throw ValidationException::withMessages(['section' => 'Il media e consentito solo per il template immagine e testo.']);
        }

        $request->validate(['image' => ['required', 'image', 'max:10240']]);
        $storedPath = $request->file('image')->store("blog-posts/{$blogPost->id}/sections/{$section->id}", 'public');
        $extra = $section->extra_json ?? [];
        $oldPath = $extra['image_path'] ?? null;
        $extra['image_path'] = $storedPath;
        $section->forceFill(['extra_json' => $extra])->save();
        if (is_string($oldPath) && str_starts_with($oldPath, "blog-posts/{$blogPost->id}/sections/{$section->id}/") && $oldPath !== $storedPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'section_id' => $section->id,
            'image_path' => $storedPath,
            'image_url' => PublicMediaUrl::fromPublicDisk($storedPath, $request),
            'original_name' => $request->file('image')->getClientOriginalName(),
        ]);
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'max:10240']]);
        $storedPath = $request->file('image')->store('blog-posts/drafts/'.Str::uuid(), 'public');

        return response()->json([
            'image_path' => $storedPath,
            'image_url' => PublicMediaUrl::fromPublicDisk($storedPath, $request),
            'original_name' => $request->file('image')->getClientOriginalName(),
        ]);
    }

    private function persist(BlogPost $blogPost, array $payload): BlogPost
    {
        $previousSlug = $blogPost->exists ? $blogPost->slug : null;
        $relationsPayload = ['faqs' => $payload['faqs'] ?? []];
        $sections = $payload['sections'] ?? null;
        $syncRelatedServices = array_key_exists('related_service_ids', $payload);
        $syncRelatedArticles = array_key_exists('related_article_ids', $payload);
        $relatedServiceIds = $payload['related_service_ids'] ?? [];
        $relatedArticleIds = $payload['related_article_ids'] ?? [];

        unset($payload['sections'], $payload['faqs'], $payload['related_service_ids'], $payload['related_article_ids']);

        $blogPost->fill($payload);
        $blogPost->save();

        if (is_array($sections)) {
            $this->persistEditorialSections($blogPost, $sections);
        }
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
        return ['editorialCategory', 'sections', 'faqs', 'relatedServices', 'relatedArticles'];
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

    /** @param list<array<string, mixed>> $sections */
    private function persistEditorialSections(BlogPost $blogPost, array $sections): void
    {
        $existing = $blogPost->sections()->get()->keyBy('id');
        $retainedIds = [];

        foreach ($sections as $index => $payload) {
            $id = isset($payload['id']) ? (int) $payload['id'] : null;
            $section = $id === null ? null : $existing->get($id);
            if ($id !== null && $section === null) {
                throw ValidationException::withMessages(["sections.$index.id" => 'La sezione non appartiene a questo articolo.']);
            }

            $template = $payload['template'] ?? 'text';
            $extra = $payload['extra_json'] ?? ($section?->extra_json ?? []);
            $extra = is_array($extra) ? $extra : [];
            if ($template === 'image_text') {
                $extra['image_path'] = $payload['image_path'] ?? ($extra['image_path'] ?? null);
            } else {
                unset($extra['image_path']);
            }

            $attributes = [
                'key' => $payload['key'] ?? $section?->key ?? 'section-'.Str::uuid(),
                'template' => $template,
                'title' => trim((string) $payload['title']),
                'subtitle' => $payload['subtitle'] ?? null,
                'content' => trim((string) $payload['content']),
                'extra_json' => $extra ?: null,
                'sort_order' => $index,
                'is_active' => $payload['is_active'] ?? true,
            ];
            if ($section === null) {
                $section = $blogPost->sections()->create($attributes);
            } else {
                $section->fill($attributes)->save();
            }
            $retainedIds[] = $section->id;
        }

        $blogPost->sections()->whereNotIn('id', $retainedIds)->delete();
    }
}
