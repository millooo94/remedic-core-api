<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfessionalTimeBlockResource;
use App\Models\ProfessionalTimeBlock;
use App\Services\ProfessionalAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProfessionalTimeBlockController extends Controller
{
    public function __construct(
        private readonly ProfessionalAvailabilityService $availabilityService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $blocks = ProfessionalTimeBlock::query()
            ->with('professional')
            ->when($filters['professional_id'] ?? null, fn ($query, int $professionalId) => $query->where('professional_id', $professionalId))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('ends_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->get();

        return ProfessionalTimeBlockResource::collection($blocks);
    }

    public function store(Request $request): ProfessionalTimeBlockResource
    {
        $payload = $this->validatedPayload($request);
        $this->availabilityService->assertBlockDoesNotOverlap($payload);

        $block = ProfessionalTimeBlock::query()->create($payload);

        return new ProfessionalTimeBlockResource($block->load('professional'));
    }

    public function update(Request $request, ProfessionalTimeBlock $professionalTimeBlock): ProfessionalTimeBlockResource
    {
        $payload = $this->validatedPayload($request);
        $this->availabilityService->assertBlockDoesNotOverlap($payload, $professionalTimeBlock->id);

        $professionalTimeBlock->fill($payload);
        $professionalTimeBlock->save();

        return new ProfessionalTimeBlockResource($professionalTimeBlock->refresh()->load('professional'));
    }

    public function destroy(ProfessionalTimeBlock $professionalTimeBlock): Response
    {
        $professionalTimeBlock->delete();

        return response()->noContent();
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'type' => ['required', Rule::in(['ferie', 'blocco', 'permesso', 'altro'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
