<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfessionalAvailabilityExceptionResource;
use App\Models\ProfessionalAvailabilityException;
use App\Services\ProfessionalAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProfessionalAvailabilityExceptionController extends Controller
{
    public function __construct(
        private readonly ProfessionalAvailabilityService $availabilityService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $exceptions = $this->availabilityService->manualExceptionsQuery()
            ->with('professional')
            ->when($filters['professional_id'] ?? null, fn ($query, int $professionalId) => $query->where('professional_id', $professionalId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return ProfessionalAvailabilityExceptionResource::collection($exceptions);
    }

    public function store(Request $request): ProfessionalAvailabilityExceptionResource
    {
        $payload = $this->validatedPayload($request);
        $this->availabilityService->assertExceptionDoesNotOverlap($payload);

        $exception = ProfessionalAvailabilityException::query()->create($payload);

        return new ProfessionalAvailabilityExceptionResource($exception->load('professional'));
    }

    public function update(Request $request, ProfessionalAvailabilityException $availabilityException): ProfessionalAvailabilityExceptionResource
    {
        $payload = $this->validatedPayload($request);
        $this->availabilityService->assertExceptionDoesNotOverlap($payload, $availabilityException->id);

        $availabilityException->fill($payload);
        $availabilityException->save();

        return new ProfessionalAvailabilityExceptionResource($availabilityException->refresh()->load('professional'));
    }

    public function destroy(ProfessionalAvailabilityException $availabilityException): Response
    {
        $availabilityException->delete();

        return response()->noContent();
    }

    private function validatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::in(['available', 'unavailable'])],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        return $validated;
    }
}
