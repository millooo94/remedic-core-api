<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Settings\UpdateSettingsRequest;
use App\Http\Resources\Api\V1\ApplicationSettingResource;
use App\Services\SettingsService;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }

    public function show(): ApplicationSettingResource
    {
        return ApplicationSettingResource::make($this->settingsService->get());
    }

    public function update(UpdateSettingsRequest $request): ApplicationSettingResource
    {
        return ApplicationSettingResource::make(
            $this->settingsService->update($request->validated(), $request->user()),
        );
    }
}
