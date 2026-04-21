<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $service,
    ) {
    }

    public function summary(Request $request): array
    {
        return $this->service->summary($request->all());
    }

    public function monthlyTrends(Request $request): array
    {
        return $this->service->monthlyTrends($request->all());
    }
}
