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
        $all = BlogPost::query()->active()->published()->where('content_type', $type)->orderByDesc('published_at')->orderByDesc('id');
        $configuration = $page->configuration ?? [];
        $featured = $this->publishedSelected($configuration['featured_post_id'] ?? null, $type) ?? $all->first();
        $used = $featured ? [$featured->id] : [];
        $latest = (clone $all)->whereNotIn('id', $used)->limit(3)->get();
        $used = [...$used, ...$latest->pluck('id')->all()];
        $secondary = $type === 'news' ? ($this->publishedSelected($configuration['secondary_post_id'] ?? null, $type) ?? (clone $all)->whereNotIn('id', $used)->first()) : null;
        $archive = clone $all;
        if ($category = trim((string) $request->query('category'))) {
            $archive->where('editorial_category', $category);
        }
        if ($term = trim((string) $request->query('q'))) {
            $archive->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('excerpt', 'like', "%{$term}%")->orWhere('intro_text', 'like', "%{$term}%"));
        }
        $items = $archive->get();

        return ['featured' => $featured ? $this->item($featured) : null, 'latest' => $latest->map(fn (BlogPost $post) => $this->item($post))->all(), 'secondary' => $secondary ? $this->item($secondary) : null, 'items' => $items->map(fn (BlogPost $post) => $this->item($post))->all(), 'result_count' => $items->count(), 'available_categories' => $this->categories($type, $all->get())];
    }

    public function admin(SiteIndexPage $page, Request $request): array
    {
        $type = $page->internal_key === 'news_index' ? 'news' : 'health_pill';
        $data = $this->public($page, $request);
        $data['candidates'] = BlogPost::query()->where('content_type', $type)->orderBy('title')->get()->map(fn (BlogPost $post) => ['id' => $post->id, 'title' => $post->title, 'category_label' => BlogPost::editorialCategories($type)[$post->editorial_category] ?? null, 'publication_state' => $post->publicationState()->value, 'is_public' => $post->isPubliclyAvailable()])->all();

        return $data;
    }

    private function publishedSelected(mixed $id, string $type): ?BlogPost
    {
        return $id ? BlogPost::query()->active()->published()->where('content_type', $type)->find((int) $id) : null;
    }

    private function categories(string $type, $posts): array
    {
        $labels = BlogPost::editorialCategories($type);
        $available = $posts->pluck('editorial_category')->filter()->unique();

        return $available->map(fn ($key) => ['key' => $key, 'label' => $labels[$key] ?? $key])->values()->all();
    }

    private function item(BlogPost $post): array
    {
        return ['title' => $post->title, 'excerpt' => $post->excerpt ?: $post->intro_text ?: '', 'image_url' => $post->cover_image, 'published_at' => $post->published_at?->toIso8601String(), 'date' => $post->published_at?->translatedFormat('j F Y'), 'category_key' => $post->editorial_category, 'category_label' => BlogPost::editorialCategories($post->content_type)[$post->editorial_category] ?? $post->category_label, 'public_slug' => $post->slug, 'href' => $post->canonicalHref(), 'publication_state' => $post->publicationState()->value];
    }
}
