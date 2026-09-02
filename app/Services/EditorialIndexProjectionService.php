<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\SiteIndexPage;
use Illuminate\Http\Request;

class EditorialIndexProjectionService
{
    public function public(SiteIndexPage $page, Request $request): array
    {
        $type = $page->internal_key === 'news_index' ? 'news' : 'health_pill';
        $all = BlogPost::query()->with('editorialCategory')->active()->published()->where('content_type', $type)->orderByDesc('published_at')->orderByDesc('id');
        $configuration = $page->configuration ?? [];
        $featured = $this->publishedSelected($configuration['featured_post_id'] ?? null, $type) ?? $all->first();
        $used = $featured ? [$featured->id] : [];
        $latest = (clone $all)->whereNotIn('id', $used)->limit(3)->get();
        $used = [...$used, ...$latest->pluck('id')->all()];
        $secondary = $type === 'news' ? ($this->publishedSelected($configuration['secondary_post_id'] ?? null, $type) ?? (clone $all)->whereNotIn('id', $used)->first()) : null;
        $archive = clone $all;
        if ($categoryId = $request->integer('category_id')) {
            $archive->where('editorial_category_id', $categoryId);
        }
        if ($term = trim((string) $request->query('q'))) {
            $archive->where(fn ($query) => $query->where('title', 'like', "%{$term}%")->orWhere('excerpt', 'like', "%{$term}%")->orWhere('intro_text', 'like', "%{$term}%"));
        }
        $items = $archive->get();

        return ['featured' => $featured ? $this->item($featured) : null, 'latest' => $latest->map(fn (BlogPost $post) => $this->item($post))->all(), 'secondary' => $secondary ? $this->item($secondary) : null, 'items' => $items->map(fn (BlogPost $post) => $this->item($post))->all(), 'result_count' => $items->count(), 'available_categories' => $this->categories($all->get())];
    }

    public function admin(SiteIndexPage $page, Request $request): array
    {
        $type = $page->internal_key === 'news_index' ? 'news' : 'health_pill';
        $data = $this->public($page, $request);
        $data['candidates'] = BlogPost::query()->with('editorialCategory')->where('content_type', $type)->orderBy('title')->get()->map(fn (BlogPost $post) => ['id' => $post->id, 'title' => $post->title, 'category_label' => $post->editorialCategory?->name, 'publication_state' => $post->publicationState()->value, 'is_public' => $post->isPubliclyAvailable()])->all();

        return $data;
    }

    private function publishedSelected(mixed $id, string $type): ?BlogPost
    {
        return $id ? BlogPost::query()->with('editorialCategory')->active()->published()->where('content_type', $type)->find((int) $id) : null;
    }

    private function categories($posts): array
    {
        return $posts->map(fn (BlogPost $post) => $post->editorialCategory ? ['id' => $post->editorialCategory->id, 'label' => $post->editorialCategory->name] : null)->filter()->unique('id')->sortBy('label')->values()->all();
    }

    private function item(BlogPost $post): array
    {
        return ['title' => $post->title, 'excerpt' => $post->excerpt ?: $post->intro_text ?: '', 'image_url' => $post->cover_image, 'published_at' => $post->published_at?->toIso8601String(), 'date' => $post->published_at?->translatedFormat('j F Y'), 'category_id' => $post->editorial_category_id, 'category_label' => $post->editorialCategory?->name ?? $post->category_label, 'public_slug' => $post->slug, 'href' => $post->canonicalHref(), 'publication_state' => $post->publicationState()->value];
    }
}
