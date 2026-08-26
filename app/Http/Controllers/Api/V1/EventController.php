<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventLocationType;
use App\Enums\EventOperationalStatus;
use App\Enums\EventRegistrationMode;
use App\Enums\EventType;
use App\Http\Controllers\Controller;
use App\Models\Checkup;
use App\Models\Event;
use App\Models\Professional;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\Specialization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function index(Request $r)
    {
        $q = Event::query();
        $f = $r->validate(['search' => ['nullable', 'string'], 'event_type' => ['nullable', Rule::enum(EventType::class)], 'operational_status' => ['nullable', Rule::enum(EventOperationalStatus::class)], 'location_type' => ['nullable', Rule::enum(EventLocationType::class)], 'archive_state' => ['nullable', 'in:active,archived,all']]);
        match ($f['archive_state'] ?? 'active') {
            'archived' => $q->onlyTrashed(),'all' => $q->withTrashed(),default => null
        };
        foreach (['event_type', 'operational_status', 'location_type'] as $k) {
            if (isset($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }if (filled($f['search'] ?? null)) {
            $q->where('name', 'like', '%'.$f['search'].'%');
        }

return response()->json(['data' => $q->with($this->relations())->orderByDesc('start_at')->get()->map(fn (Event $e) => $this->data($e))->values()]);
    }

    public function show(Event $event)
    {
        return response()->json($this->data($event->load($this->relations())));
    }

    public function store(Request $r)
    {
        $e = new Event;
        $this->persist($e, $r);

        return response()->json($this->data($e->load($this->relations())), 201);
    }

    public function update(Request $r, Event $event)
    {
        $this->persist($event, $r);

        return response()->json($this->data($event->load($this->relations())));
    }

    public function destroy(Event $event)
    {
        $event->delete();

        return response()->noContent();
    }

    public function restore(int $event)
    {
        $e = Event::withTrashed()->findOrFail($event);
        $e->restore();

        return response()->json($this->data($e->load($this->relations())));
    }

    public function lookups()
    {
        return response()->json(['data' => ['professionals' => Professional::query()->orderBy('full_name')->get(['id', 'full_name', 'is_active']), 'specializations' => Specialization::query()->orderBy('name')->get(['id', 'name', 'is_active']), 'services' => Service::query()->orderBy('display_name')->get(['id', 'display_name', 'is_active']), 'checkups' => Checkup::query()->orderBy('display_name')->get(['id', 'display_name', 'is_active']), 'promotions' => Promotion::query()->orderBy('name')->get(['id', 'name', 'is_active'])]]);
    }

    private function persist(Event $e, Request $r): void
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:190'], 'event_type' => ['required', Rule::enum(EventType::class)], 'operational_status' => ['required', Rule::enum(EventOperationalStatus::class)], 'start_at' => ['required', 'date'], 'end_at' => ['required', 'date', 'after:start_at'], 'location_type' => ['required', Rule::enum(EventLocationType::class)], 'external_venue_name' => ['nullable', 'string'], 'external_venue_address' => ['nullable', 'string'], 'online_url' => ['nullable', 'url'], 'registration_required' => ['required', 'boolean'], 'registration_deadline' => ['nullable', 'date', 'before_or_equal:start_at'], 'registration_mode' => ['required', Rule::enum(EventRegistrationMode::class)], 'external_registration_url' => ['nullable', 'url'], 'capacity' => ['nullable', 'integer', 'min:1'], 'participation_price' => ['nullable', 'numeric', 'min:0'], 'cancellation_reason' => ['nullable', 'string'], 'internal_notes' => ['nullable', 'string'], 'professional_ids' => ['array'], 'professional_ids.*' => ['integer', 'exists:professionals,id'], 'specialization_ids' => ['array'], 'specialization_ids.*' => ['integer', 'exists:specializations,id'], 'service_ids' => ['array'], 'service_ids.*' => ['integer', 'exists:services,id'], 'checkup_ids' => ['array'], 'checkup_ids.*' => ['integer', 'exists:checkups,id'], 'promotion_ids' => ['array'], 'promotion_ids.*' => ['integer', 'exists:promotions,id']]);
        if (! $d['registration_required'] && ($d['registration_mode'] !== 'none' || $d['registration_deadline'] || $d['external_registration_url'])) {
            throw ValidationException::withMessages(['registration_mode' => 'Senza iscrizione la modalità deve essere Nessuna iscrizione.']);
        }if ($d['registration_required'] && $d['registration_mode'] === 'none') {
            throw ValidationException::withMessages(['registration_mode' => 'Seleziona una modalità di iscrizione.']);
        }if ($d['registration_mode'] === 'external_url' && ! $d['external_registration_url']) {
            throw ValidationException::withMessages(['external_registration_url' => 'Inserisci il link esterno di iscrizione.']);
        }if ($d['operational_status'] === 'confirmed' && $d['location_type'] === 'online' && ! $d['online_url']) {
            throw ValidationException::withMessages(['online_url' => 'Inserisci il link dell’evento online.']);
        }foreach (['external_venue_name', 'external_venue_address', 'online_url'] as $k) {
            if (($d['location_type'] === 'remedic') || ($d['location_type'] === 'external' && $k === 'online_url') || ($d['location_type'] === 'online' && str_starts_with($k, 'external_'))) {
                $d[$k] = null;
            }
        }$e->fill($d)->save();
        $e->professionals()->sync($d['professional_ids'] ?? []);
        $e->specializations()->sync($d['specialization_ids'] ?? []);
        $e->services()->sync($d['service_ids'] ?? []);
        $e->checkups()->sync($d['checkup_ids'] ?? []);
        $e->promotions()->sync($d['promotion_ids'] ?? []);
    }

    private function relations(): array
    {
        return ['professionals', 'specializations', 'services', 'checkups', 'promotions'];
    }

    private function data(Event $e): array
    {
        return ['id' => $e->id, 'name' => $e->name, 'event_type' => $e->event_type->value, 'operational_status' => $e->operational_status->value, 'temporal_status' => $e->temporalStatus(), 'is_effectively_available' => ! $e->trashed() && $e->operational_status === EventOperationalStatus::CONFIRMED && $e->temporalStatus() !== 'ended', 'start_at' => $e->start_at->toIso8601String(), 'end_at' => $e->end_at->toIso8601String(), 'location_type' => $e->location_type->value, 'external_venue_name' => $e->external_venue_name, 'external_venue_address' => $e->external_venue_address, 'online_url' => $e->online_url, 'registration_required' => $e->registration_required, 'registration_deadline' => $e->registration_deadline?->toIso8601String(), 'registration_mode' => $e->registration_mode->value, 'external_registration_url' => $e->external_registration_url, 'is_registration_open' => $e->isRegistrationOpen(), 'capacity' => $e->capacity, 'participation_price' => $e->participation_price, 'cancellation_reason' => $e->cancellation_reason, 'internal_notes' => $e->internal_notes, 'is_archived' => $e->trashed(), 'relations' => collect($this->relations())->mapWithKeys(fn ($k) => [$k => $e->$k->map(fn ($x) => ['id' => $x->id, 'name' => $x->full_name ?? $x->display_name ?? $x->name, 'is_active' => $x->is_active])->values()])->all()];
    }
}
