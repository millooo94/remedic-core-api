<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\CenterSettings\UpdateCenterSettingRequest;
use App\Http\Requests\Api\V1\Management\CenterSettings\UploadCenterLogoRequest;
use App\Http\Resources\Api\V1\Management\CenterSettingResource;
use App\Services\CenterProfileService;

class CenterSettingController extends Controller
{
    public function __construct(private readonly CenterProfileService $service) {}

    public function show(): CenterSettingResource
    {
        return new CenterSettingResource($this->service->current());
    }

    public function update(UpdateCenterSettingRequest $request): CenterSettingResource
    {
        return new CenterSettingResource($this->service->update($request->validated()));
    }

    public function uploadLogo(UploadCenterLogoRequest $request): CenterSettingResource
    {
        return new CenterSettingResource($this->service->replaceLogo($request->file('logo')));
    }

    public function deleteLogo(): CenterSettingResource
    {
        return new CenterSettingResource($this->service->removeLogo());
    }
}
