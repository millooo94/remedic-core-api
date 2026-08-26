<?php

namespace App\Models;

use App\Enums\EventLocationType;
use App\Enums\EventOperationalStatus;
use App\Enums\EventRegistrationMode;
use App\Enums\EventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'event_type', 'operational_status', 'start_at', 'end_at', 'location_type', 'external_venue_name', 'external_venue_address', 'online_url', 'registration_required', 'registration_deadline', 'registration_mode', 'external_registration_url', 'capacity', 'participation_price', 'cancellation_reason', 'internal_notes'];

    protected function casts(): array
    {
        return ['event_type' => EventType::class, 'operational_status' => EventOperationalStatus::class, 'location_type' => EventLocationType::class, 'registration_mode' => EventRegistrationMode::class, 'start_at' => 'datetime', 'end_at' => 'datetime', 'registration_deadline' => 'datetime', 'registration_required' => 'boolean', 'capacity' => 'integer', 'participation_price' => 'decimal:2'];
    }

    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'event_professional')->withTimestamps();
    }

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(Specialization::class, 'event_specialization')->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'event_service')->withTimestamps();
    }

    public function checkups(): BelongsToMany
    {
        return $this->belongsToMany(Checkup::class, 'checkup_event')->withTimestamps();
    }

    public function promotions(): BelongsToMany
    {
        return $this->belongsToMany(Promotion::class, 'event_promotion')->withTimestamps();
    }

    public function temporalStatus(): string
    {
        return $this->start_at->isFuture() ? 'upcoming' : ($this->end_at->lte(now()) ? 'ended' : 'ongoing');
    }

    public function isRegistrationOpen(): bool
    {
        return $this->registration_required && $this->operational_status !== EventOperationalStatus::CANCELLED && $this->temporalStatus() !== 'ended' && ($this->registration_deadline === null || $this->registration_deadline->gte(now()));
    }

    public function isEffectivelyAvailable(): bool
    {
        return ! $this->trashed() && $this->operational_status === EventOperationalStatus::CONFIRMED && $this->temporalStatus() !== 'ended';
    }
}
