<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Services\ProfessionalStatementService;
use Illuminate\Http\Request;

class ProfessionalStatementController extends Controller
{
    public function __construct(
        private readonly ProfessionalStatementService $service,
    ) {
    }

    public function show(Request $request, Professional $professional): array
    {
        $filters = $this->validatedDates($request);

        return $this->service->build(
            $professional,
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );
    }

    public function pdf(Request $request, Professional $professional)
    {
        $filters = $this->validatedDates($request);

        return $this->service->downloadPdf(
            $professional,
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );
    }

    public function excel(Request $request, Professional $professional)
    {
        $filters = $this->validatedDates($request);

        return $this->service->downloadExcel(
            $professional,
            $filters['start_date'] ?? null,
            $filters['end_date'] ?? null,
        );
    }

    private function validatedDates(Request $request): array
    {
        return $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }
}
