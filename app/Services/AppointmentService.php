<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use App\Models\ProfessionalService;
use App\Models\ProfessionalTimeBlock;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        private readonly ProfessionalAvailabilityService $professionalAvailabilityService,
    ) {
    }

    public function create(array $payload, User $actor): Appointment
    {
        return DB::transaction(function () use ($payload, $actor): Appointment {
            $this->validatePayload($payload);

            $appointment = Appointment::query()->create([
                ...$payload,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            return $appointment->load(['patient', 'professional', 'service.category']);
        });
    }

    public function update(Appointment $appointment, array $payload, User $actor): Appointment
    {
        return DB::transaction(function () use ($appointment, $payload, $actor): Appointment {
            $nextPayload = array_merge($appointment->only([
                'patient_id',
                'professional_id',
                'service_id',
                'starts_at',
                'ends_at',
                'status',
                'notes',
            ]), $payload);

            $this->validatePayload($nextPayload, $appointment);

            $appointment->fill($payload);
            $appointment->updated_by = $actor->id;
            $appointment->save();

            return $appointment->refresh()->load(['patient', 'professional', 'service.category']);
        });
    }

    public function move(Appointment $appointment, array $payload, User $actor): Appointment
    {
        return $this->update($appointment, [
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'],
            'professional_id' => $payload['professional_id'] ?? $appointment->professional_id,
        ], $actor);
    }

    private function validatePayload(array $payload, ?Appointment $existing = null): void
    {
        $startsAt = Carbon::parse($payload['starts_at']);
        $endsAt = Carbon::parse($payload['ends_at']);
        $professionalId = (int) $payload['professional_id'];
        $serviceId = (int) $payload['service_id'];

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'L\'orario di fine deve essere successivo all\'orario di inizio.',
            ]);
        }

        if (! $startsAt->isSameDay($endsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'Un appuntamento deve iniziare e terminare nello stesso giorno.',
            ]);
        }

        $serviceLinked = ProfessionalService::query()
            ->where('professional_id', $professionalId)
            ->where('service_id', $serviceId)
            ->where('is_active', true)
            ->exists();

        if (! $serviceLinked) {
            throw ValidationException::withMessages([
                'service_id' => 'La prestazione selezionata non e associata al professionista.',
            ]);
        }

        if (! $this->isProfessionalAvailable($professionalId, $startsAt, $endsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Il professionista non risulta disponibile nell\'orario selezionato.',
            ]);
        }

        if ($this->hasBlockingTimeOff($professionalId, $startsAt, $endsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Il professionista ha un blocco o ferie nell\'orario selezionato.',
            ]);
        }

        if ($this->hasOverlappingAppointment($professionalId, $startsAt, $endsAt, $existing?->id)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Esiste gia un appuntamento sovrapposto per questo professionista.',
            ]);
        }
    }

    private function isProfessionalAvailable(int $professionalId, Carbon $startsAt, Carbon $endsAt): bool
    {
        $date = $startsAt->toDateString();
        $startTime = $startsAt->format('H:i:s');
        $endTime = $endsAt->format('H:i:s');

        $unavailableException = $this->professionalAvailabilityService->manualExceptionsQuery()
            ->where('professional_id', $professionalId)
            ->whereDate('date', $date)
            ->where('type', 'unavailable')
            ->where(function ($query) use ($startTime, $endTime): void {
                $query
                    ->whereNull('start_time')
                    ->orWhereNull('end_time')
                    ->orWhere(function ($timeQuery) use ($startTime, $endTime): void {
                        $timeQuery
                            ->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);
                    });
            })
            ->exists();

        if ($unavailableException) {
            return false;
        }

        $availableException = $this->professionalAvailabilityService->manualExceptionsQuery()
            ->where('professional_id', $professionalId)
            ->whereDate('date', $date)
            ->where('type', 'available')
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->where('start_time', '<=', $startTime)
            ->where('end_time', '>=', $endTime)
            ->exists();

        if ($availableException) {
            return true;
        }

        $hasConfiguredRules = $this->professionalAvailabilityService->manualRulesQuery()
            ->where('professional_id', $professionalId)
            ->exists();

        if (! $hasConfiguredRules) {
            return true;
        }

        return $this->professionalAvailabilityService->manualRulesQuery()
            ->where('professional_id', $professionalId)
            ->where('weekday', $startsAt->dayOfWeekIso)
            ->where('is_active', true)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date);
            })
            ->where('start_time', '<=', $startTime)
            ->where('end_time', '>=', $endTime)
            ->exists();
    }

    private function hasBlockingTimeOff(int $professionalId, Carbon $startsAt, Carbon $endsAt): bool
    {
        return ProfessionalTimeBlock::query()
            ->where('professional_id', $professionalId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function hasOverlappingAppointment(int $professionalId, Carbon $startsAt, Carbon $endsAt, ?int $exceptId = null): bool
    {
        return Appointment::query()
            ->where('professional_id', $professionalId)
            ->where('status', '!=', 'annullato')
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }
}
