<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentService $service,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:120'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'specialization_id' => ['nullable', 'integer', 'exists:specializations,id'],
            'status' => ['nullable', Rule::in(['prenotato', 'confermato', 'effettuato', 'annullato', 'no_show'])],
        ]);

        $appointments = Appointment::query()
            ->with(['patient', 'professional.specializations', 'service.category'])
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('ends_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('starts_at', '<=', $to))
            ->when($filters['professional_id'] ?? null, fn ($query, int $professionalId) => $query->where('professional_id', $professionalId))
            ->when($filters['service_id'] ?? null, fn ($query, int $serviceId) => $query->where('service_id', $serviceId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['specialization_id'] ?? null, function ($query, int $specializationId): void {
                $query->whereHas('professional.specializations', fn ($nested) => $nested->where('specializations.id', $specializationId));
            })
            ->when($filters['q'] ?? null, function ($query, string $search): void {
                $query->whereHas('patient', function ($patientQuery) use ($search): void {
                    $patientQuery
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('starts_at')
            ->get();

        return AppointmentResource::collection($appointments);
    }

    public function store(Request $request): AppointmentResource
    {
        return new AppointmentResource($this->service->create($this->validatedPayload($request), $request->user()));
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load(['patient', 'professional', 'service.category']));
    }

    public function update(Request $request, Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($this->service->update($appointment, $this->validatedPayload($request), $request->user()));
    }

    public function move(Request $request, Appointment $appointment): AppointmentResource
    {
        $payload = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ]);

        return new AppointmentResource($this->service->move($appointment, $payload, $request->user()));
    }

    public function destroy(Appointment $appointment): Response
    {
        $appointment->delete();

        return response()->noContent();
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'professional_id' => ['required', 'integer', 'exists:professionals,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in(['prenotato', 'confermato', 'effettuato', 'annullato', 'no_show'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
