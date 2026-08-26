<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagedMediaService;
use App\Services\SiteNavigationInitializer;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;

class SiteNavigationMediaController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media, private readonly SiteNavigationInitializer $initializer) {}

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

    private function media($navigation, Request $request): array
    {
        return ['center_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->center_mega_menu_promo_image_path, $request), 'medical_areas_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->medical_areas_mega_menu_promo_image_path, $request)];
    }
}
