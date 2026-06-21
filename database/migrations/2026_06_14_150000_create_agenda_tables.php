<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignId('professional_id')->constrained('professionals')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->dateTime('starts_at')->index('appt_start_idx');
            $table->dateTime('ends_at')->index('appt_end_idx');
            $table->enum('status', ['prenotato', 'confermato', 'effettuato', 'annullato', 'no_show'])->default('prenotato')->index('appt_status_idx');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['professional_id', 'starts_at', 'ends_at'], 'appt_prof_start_idx');
            $table->index(['patient_id', 'starts_at'], 'appt_patient_start_idx');
            $table->index(['status', 'starts_at'], 'appt_status_start_idx');
        });

        Schema::create('professional_availability_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday')->index('pa_rules_weekday_idx');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true)->index('pa_rules_active_idx');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['professional_id', 'weekday', 'is_active'], 'pa_rules_prof_weekday_active_idx');
            $table->index(['professional_id', 'valid_from', 'valid_until'], 'pa_rules_valid_idx');
        });

        Schema::create('professional_availability_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->date('date')->index('pa_exc_date_idx');
            $table->enum('type', ['available', 'unavailable'])->index('pa_exc_type_idx');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['professional_id', 'date', 'type'], 'pa_exc_prof_date_type_idx');
        });

        Schema::create('professional_time_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->dateTime('starts_at')->index('pt_blocks_start_idx');
            $table->dateTime('ends_at')->index('pt_blocks_end_idx');
            $table->enum('type', ['ferie', 'blocco', 'permesso', 'altro'])->default('blocco')->index('pt_blocks_type_idx');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['professional_id', 'starts_at', 'ends_at'], 'pt_blocks_prof_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_time_blocks');
        Schema::dropIfExists('professional_availability_exceptions');
        Schema::dropIfExists('professional_availability_rules');
        Schema::dropIfExists('appointments');
    }
};
