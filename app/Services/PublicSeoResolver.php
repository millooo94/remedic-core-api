<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;

/** Runtime-only SEO projection. It deliberately never persists fallback values. */
class PublicSeoResolver
{
    public function resolve(array $content, string $path, Request $request, string $type = 'website'): array
    {
        $settings = SiteSetting::current();
        $siteName = trim((string) ($settings->site_name ?: $settings->brand_name ?: $settings->clinic_name ?: 'Remedic'));
        $fallbackTitle = trim((string) $settings->default_meta_title) ?: $siteName;
        $title = trim((string) ($content['seo_title'] ?? '')) ?: trim((string) ($content['title'] ?? '')) ?: $fallbackTitle;
        $description = $this->plainText($content['seo_description'] ?? null)
            ?: $this->plainText($content['description'] ?? null)
            ?: $this->plainText($settings->default_meta_description);
        $canonicalUrl = $this->canonicalUrl($settings->site_url, $path);
        $image = $content['image_url'] ?? null;
        if (! filled($image)) {
            $image = PublicMediaUrl::fromPublicDisk($settings->default_og_image_path, $request);
        }
        $robots = ! $settings->seo_indexing_enabled
            ? 'noindex,nofollow'
            : ($content['robots']?->value ?? $content['robots'] ?? 'index,follow');

        return [
            'title' => $this->composeTitle($title, $siteName, $fallbackTitle),
            'description' => $description,
            'canonical_url' => $canonicalUrl,
            'robots' => $robots,
            'open_graph' => [
                'title' => trim((string) ($content['og_title'] ?? '')) ?: $this->composeTitle($title, $siteName, $fallbackTitle),
                'description' => $this->plainText($content['og_description'] ?? null) ?: $description,
                'image_url' => $image,
                'url' => $canonicalUrl,
                'type' => $type,
            ],
            'twitter_card' => 'summary_large_image',
        ];
    }

    public function canonicalUrl(?string $baseUrl, string $path): ?string
    {
        $base = trim((string) $baseUrl);
        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($base);
        if (! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! filled($parts['host'])) {
            return null;
        }

        $authority = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $authority.$this->normalizePath($path);
    }

    public function normalizePath(string $path): string
    {
        $path = (string) parse_url(trim($path), PHP_URL_PATH);
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?: '/';

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function plainText(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return $text === '' ? null : mb_strimwidth($text, 0, 320, '…');
    }

    private function composeTitle(string $title, string $siteName, string $fallbackTitle): string
    {
        if ($title === $fallbackTitle || $siteName === '' || str_contains(mb_strtolower($title), mb_strtolower($siteName))) {
            return $title;
        }

        return $title.' | '.$siteName;
    }
}
