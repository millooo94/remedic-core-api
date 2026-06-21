<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfessionalAvailabilityRuleResource;
use App\Models\ProfessionalAvailabilityRule;
use App\Services\ProfessionalAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProfessionalAvailabilityRuleController extends Controller
{
    public function __construct(
        private readonly ProfessionalAvailabilityService $availabilityService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        $rules = $this->availabilityService->manualRulesQuery()
            ->with('professional')
            ->when($filters['professional_id'] ?? null, fn ($query, int $professionalId) => $query->where('professional_id', $professionalId))
            ->orderBy('professional_id')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        return ProfessionalAvailabilityRuleResource::collection($rules);
    }

    public function store(Request $request): ProfessionalAvailabilityRuleResource
    {
        $payload = $this->validatedPayload($request);
        $this->availabilityService->assertRuleDoesNotOverlap($payload);

        $rule = ProfessionalAvailabilityRule::query()->create($payload);

        return new ProfessionalAvailabilityRuleResource($rule->load('professional'));
    }

    public function update(Request $request, ProfessionalAvailabilityRule $professionalAvailabilityRule): ProfessionalAvailabilityRuleResource
    {
        $payload = $this->validatedPayload($request);
        $this->availabilityService->assertRuleDoesNotOverlap($payload, $professionalAvailabilityRule->id);

        $professionalAvailabilityRule->fill($payload);
        $professionalAvailabilityRule->save();

        return new ProfessionalAvailabilityRuleResource($professionalAvailabilityRule->refresh()->load('professional'));
    }

    public function destroy(ProfessionalAvailabilityRule $professionalAvailabilityRule): Response
    {
        $professionalAvailabilityRule->delete();

        return response()->noContent();
    }

    private function validatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return [
            ...$validated,
            'source' => 'manual',
            'external_hash' => null,
            'last_synced_at' => null,
        ];
    }
}
