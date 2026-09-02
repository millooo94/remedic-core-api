<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagedMediaService;
use App\Services\SiteNavigationInitializer;
use App\Services\SiteNavigationProjectionService;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class SiteNavigationMediaController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media, private readonly SiteNavigationInitializer $initializer, private readonly SiteNavigationProjectionService $projection) {}

    public function store(Request $request)
    {
        $request->validate(['file' => ['required', 'image', 'max:51200']]);
        $navigation = $this->initializer->initialize();
        $this->media->replace($navigation, 'center_mega_menu_promo_image_path', $request->file('file'), 'site-navigation/center-mega-menu-promo-image');

        return response()->json(['data' => $this->media($navigation->refresh(), $request)]);
    }

    public function destroy(Request $request)
    {
        $navigation = $this->initializer->initialize();
        $this->media->delete($navigation, 'center_mega_menu_promo_image_path', ['site-navigation/center-mega-menu-promo-image']);

        return response()->json(['data' => $this->media($navigation->refresh(), $request)]);
    }

    public function storeAreas(Request $request)
    {
        $request->validate(['file' => ['required', 'image', 'max:51200']]);
        $navigation = $this->initializer->initialize();
        $this->media->replace($navigation, 'medical_areas_mega_menu_promo_image_path', $request->file('file'), 'site-navigation/medical-areas-mega-menu-promo-image');

        return response()->json(['data' => $this->media($navigation->refresh(), $request)]);
    }

    public function destroyAreas(Request $request)
    {
        $navigation = $this->initializer->initialize();
        $this->media->delete($navigation, 'medical_areas_mega_menu_promo_image_path', ['site-navigation/medical-areas-mega-menu-promo-image']);

        return response()->json(['data' => $this->media($navigation->refresh(), $request)]);
    }

    public function storeSectionIcon(Request $request, string $section)
    {
        $request->validate(['file' => ['required', 'image', 'mimes:png,webp', 'max:2048']]);
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $sections = $configuration['center_mega_menu']['sections'] ?? [];
        $index = collect($sections)->search(fn (array $item): bool => $item['key'] === $section);
        abort_unless($index !== false, 404);

        $oldPath = $sections[$index]['icon_path'] ?? null;
        $newPath = $request->file('file')->store('site-navigation/center-mega-menu-icons/'.$section, 'public');
        $sections[$index]['icon_path'] = $newPath;
        $configuration['center_mega_menu']['sections'] = $sections;

        try {
            DB::transaction(fn () => $navigation->update(['configuration' => $configuration]));
        } catch (Throwable $error) {
            Storage::disk('public')->delete($newPath);
            throw $error;
        }

        $this->deleteSectionIconFile($oldPath, $section);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function destroySectionIcon(Request $request, string $section)
    {
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $sections = $configuration['center_mega_menu']['sections'] ?? [];
        $index = collect($sections)->search(fn (array $item): bool => $item['key'] === $section);
        abort_unless($index !== false, 404);

        $oldPath = $sections[$index]['icon_path'] ?? null;
        $sections[$index]['icon_path'] = null;
        $configuration['center_mega_menu']['sections'] = $sections;
        DB::transaction(fn () => $navigation->update(['configuration' => $configuration]));
        $this->deleteSectionIconFile($oldPath, $section);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    private function media($navigation, Request $request): array
    {
        return ['center_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->center_mega_menu_promo_image_path, $request), 'medical_areas_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->medical_areas_mega_menu_promo_image_path, $request)];
    }

    private function deleteSectionIconFile(mixed $path, string $section): void
    {
        if (! is_string($path) || ! Str::startsWith($path, 'site-navigation/center-mega-menu-icons/'.$section.'/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
