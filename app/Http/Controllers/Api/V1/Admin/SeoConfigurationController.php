<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\SiteSettingResource;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SeoConfigurationController extends Controller
{
    public function show(): SiteSettingResource
    {
        return new SiteSettingResource(SiteSetting::current());
    }

    public function update(Request $request): SiteSettingResource
    {
        $payload = $request->validate([
            'site_url' => ['nullable', 'url', 'max:255'],
            'default_meta_title' => ['nullable', 'string', 'max:255'],
            'default_meta_description' => ['nullable', 'string'],
            'seo_indexing_enabled' => ['required', 'boolean'],
        ]);
        $settings = SiteSetting::ensureSingleton();
        $settings->fill($payload)->save();

        return new SiteSettingResource($settings->refresh());
    }

    public function uploadSocialImage(Request $request): SiteSettingResource
    {
        $request->validate(['image' => ['required', 'image', 'max:5120']]);
        $settings = SiteSetting::ensureSingleton();
        $oldPath = $settings->default_og_image_path;
        $settings->default_og_image_path = $request->file('image')->store('seo/default-social-image', 'public');
        $settings->save();
        if (is_string($oldPath) && str_starts_with($oldPath, 'seo/default-social-image/')) {
            Storage::disk('public')->delete($oldPath);
        }

        return new SiteSettingResource($settings->refresh());
    }

    public function deleteSocialImage(): SiteSettingResource
    {
        $settings = SiteSetting::ensureSingleton();
        $oldPath = $settings->default_og_image_path;
        $settings->default_og_image_path = null;
        $settings->save();
        if (is_string($oldPath) && str_starts_with($oldPath, 'seo/default-social-image/')) {
            Storage::disk('public')->delete($oldPath);
        }

        return new SiteSettingResource($settings->refresh());
    }
}
