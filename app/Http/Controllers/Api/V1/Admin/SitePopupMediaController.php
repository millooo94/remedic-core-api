<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ManagedMediaService;
use App\Services\SitePopupInitializer;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;

class SitePopupMediaController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media, private readonly SitePopupInitializer $initializer) {}

    public function store(Request $request)
    {
        $request->validate(['file' => ['required', 'image', 'max:51200']]);
        $popup = $this->initializer->initialize();
        $this->media->replace($popup, 'image_path', $request->file('file'), 'site-popup/image');

        return response()->json(['data' => ['image_url' => PublicMediaUrl::fromPublicDisk($popup->refresh()->image_path, $request)]]);
    }

    public function destroy(Request $request)
    {
        $popup = $this->initializer->initialize();
        $this->media->delete($popup, 'image_path', ['site-popup/image']);

        return response()->json(['data' => ['image_url' => PublicMediaUrl::fromPublicDisk($popup->refresh()->image_path, $request)]]);
    }
}
