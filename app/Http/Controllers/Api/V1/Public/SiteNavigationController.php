<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\SiteNavigationInitializer;
use App\Services\SiteNavigationProjectionService;
use Illuminate\Http\Request;

class SiteNavigationController extends Controller
{
    public function __construct(private readonly SiteNavigationInitializer $initializer, private readonly SiteNavigationProjectionService $projection) {}

    public function show(Request $request)
    {
        return response()->json(['data' => $this->projection->public($this->initializer->initialize(), $request)]);
    }
}
