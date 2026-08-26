<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('event_type', 32);
            $t->string('operational_status', 32)->default('planned');
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->string('location_type', 32);
            $t->string('external_venue_name')->nullable();
            $t->string('external_venue_address')->nullable();
            $t->string('online_url')->nullable();
            $t->boolean('registration_required')->default(false);
            $t->dateTime('registration_deadline')->nullable();
            $t->string('registration_mode', 32)->default('none');
            $t->string('external_registration_url')->nullable();
            $t->unsignedInteger('capacity')->nullable();
            $t->decimal('participation_price', 10, 2)->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->text('internal_notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['start_at', 'operational_status']);
        });
        foreach (['event_professional' => ['professional_id', 'professionals'], 'event_specialization' => ['specialization_id', 'specializations'], 'event_service' => ['service_id', 'services'], 'checkup_event' => ['checkup_id', 'checkups'], 'event_promotion' => ['promotion_id', 'promotions']] as $table => [$column,$related]) {
            Schema::create($table, function (Blueprint $t) use ($column, $related): void {
                $t->foreignId('event_id')->constrained()->cascadeOnDelete();
                $t->foreignId($column)->constrained($related)->restrictOnDelete();
                $t->unique(['event_id', $column]);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_promotion');
        Schema::dropIfExists('checkup_event');
        Schema::dropIfExists('event_service');
        Schema::dropIfExists('event_specialization');
        Schema::dropIfExists('event_professional');
        Schema::dropIfExists('events');
    }
};
