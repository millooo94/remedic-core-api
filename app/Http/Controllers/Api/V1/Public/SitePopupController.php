<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\SitePopupInitializer;
use App\Services\SitePopupProjectionService;
use Illuminate\Http\Request;

class SitePopupController extends Controller
{
    public function __construct(private readonly SitePopupInitializer $initializer, private readonly SitePopupProjectionService $projection) {}

    public function show(Request $request)
    {
        return response()->json(['data' => $this->projection->public($this->initializer->initialize(), $request)]);
    }
}
