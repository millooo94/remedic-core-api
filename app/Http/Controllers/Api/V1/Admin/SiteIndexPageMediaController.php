<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteIndexPage;
use App\Services\ManagedMediaService;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;

class SiteIndexPageMediaController extends Controller
{
    private const SLOTS = [
        'hero_video' => ['field' => 'hero_video_path', 'kind' => 'video'],
        'hero_poster' => ['field' => 'hero_poster_path', 'kind' => 'image'],
        'intro_split_image' => ['field' => 'intro_split_image_path', 'kind' => 'image'],
    ];

    public function __construct(private readonly ManagedMediaService $media) {}

    public function store(Request $request, SiteIndexPage $siteIndexPage, string $slot)
    {
        $definition = $this->definition($siteIndexPage, $slot);
        $request->validate(['file' => [$definition['kind'] === 'image' ? 'image' : 'file', 'max:51200']]);
        $this->media->replace($siteIndexPage, $definition['field'], $request->file('file'), "site-index-pages/{$siteIndexPage->id}/{$slot}");

        return response()->json(['data' => $this->mediaData($siteIndexPage->refresh())]);
    }

    public function destroy(SiteIndexPage $siteIndexPage, string $slot)
    {
        $definition = $this->definition($siteIndexPage, $slot);
        $this->media->delete($siteIndexPage, $definition['field'], ["site-index-pages/{$siteIndexPage->id}/{$slot}"]);

        return response()->json(['data' => $this->mediaData($siteIndexPage->refresh())]);
    }

    private function definition(SiteIndexPage $page, string $slot): array
    {
        abort_unless(isset(self::SLOTS[$slot]), 404);
        abort_unless($page->internal_key === 'aesthetic_medicine_index' || in_array($slot, ['hero_video', 'hero_poster'], true) && $page->internal_key === 'diagnostics_index', 404);
        abort_unless(! ($slot === 'intro_split_image' && $page->internal_key !== 'aesthetic_medicine_index'), 404);

        return self::SLOTS[$slot];
    }

    private function mediaData(SiteIndexPage $page): array
    {
        return [
            'hero_video_url' => PublicMediaUrl::fromPublicDisk($page->hero_video_path, request()),
            'hero_poster_url' => PublicMediaUrl::fromPublicDisk($page->hero_poster_path, request()),
            'intro_split_image_url' => PublicMediaUrl::fromPublicDisk($page->intro_split_image_path, request()),
        ];
    }
}
