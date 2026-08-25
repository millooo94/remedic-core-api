<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\PageResource;
use App\Services\HomePageInitializer;

class HomePageController extends Controller
{
    public function show(HomePageInitializer $initializer): PageResource
    {
        return new PageResource($initializer->initialize()->load(['sections', 'faqs']));
    }
}
