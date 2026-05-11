<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\PersistsSectionsAndFaqs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\BlogPosts\StoreBlogPostRequest;
use App\Http\Requests\Api\V1\Admin\BlogPosts\UpdateBlogPostRequest;
use App\Http\Resources\Api\V1\Admin\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BlogPostController extends Controller
{
    use PersistsSectionsAndFaqs;

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = BlogPost::query()->with(['sections', 'faqs']);

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

        return BlogPostResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreBlogPostRequest $request): BlogPostResource
    {
        $post = DB::transaction(fn () => $this->persist(new BlogPost(), $request->validated()));

        return new BlogPostResource($post->load(['sections', 'faqs']));
    }

    public function show(BlogPost $blogPost): BlogPostResource
    {
        return new BlogPostResource($blogPost->load(['sections', 'faqs']));
    }

    public function update(UpdateBlogPostRequest $request, BlogPost $blogPost): BlogPostResource
    {
        $post = DB::transaction(fn () => $this->persist($blogPost, $request->validated()));

        return new BlogPostResource($post->load(['sections', 'faqs']));
    }

    public function destroy(BlogPost $blogPost): Response
    {
        $blogPost->delete();

        return response()->noContent();
    }

    private function persist(BlogPost $blogPost, array $payload): BlogPost
    {
        $relationsPayload = [
            'sections' => $payload['sections'] ?? [],
            'faqs' => $payload['faqs'] ?? [],
        ];

        unset($payload['sections'], $payload['faqs']);

        $blogPost->fill($payload);
        $blogPost->save();

        $this->persistSectionsAndFaqs($blogPost, $relationsPayload);

        return $blogPost;
    }
}
