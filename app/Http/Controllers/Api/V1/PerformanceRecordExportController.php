<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PerformanceRecords\PerformanceRecordQueryRequest;
use App\Services\PerformanceRecordExportService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PerformanceRecordExportController extends Controller
{
    public function __construct(
        private readonly PerformanceRecordExportService $service,
    ) {
    }

    public function preview(PerformanceRecordQueryRequest $request): array
    {
        return $this->service->build($request->filters());
    }

    public function pdf(PerformanceRecordQueryRequest $request): Response
    {
        return $this->service->downloadPdf($request->filters());
    }

    public function excel(PerformanceRecordQueryRequest $request): BinaryFileResponse
    {
        return $this->service->downloadExcel($request->filters());
    }
}
