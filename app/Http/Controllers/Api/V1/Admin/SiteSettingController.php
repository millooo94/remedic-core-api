<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\SiteSettings\UpdateSiteSettingRequest;
use App\Http\Resources\Api\V1\Admin\SiteSettingResource;
use App\Models\SiteSetting;

class SiteSettingController extends Controller
{
    public function show(): SiteSettingResource
    {
        return new SiteSettingResource(SiteSetting::current());
    }

    public function update(UpdateSiteSettingRequest $request): SiteSettingResource
    {
        $settings = SiteSetting::ensureSingleton();
        $settings->fill($request->validated());
        $settings->save();

        return new SiteSettingResource($settings->refresh());
    }
}
