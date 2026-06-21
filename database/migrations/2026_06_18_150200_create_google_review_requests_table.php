<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_review_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performance_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_name');
            $table->string('patient_phone')->nullable();
            $table->string('professional_name')->nullable();
            $table->string('professional_title')->nullable();
            $table->string('specialization_name')->nullable();
            $table->string('review_url')->nullable();
            $table->text('message_body')->nullable();
            $table->string('status', 40)->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->json('template_payload')->nullable();
            $table->timestamps();

            $table->unique('performance_record_id', 'google_review_requests_performance_unique');
            $table->index(['patient_id', 'scheduled_at'], 'google_review_requests_patient_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_review_requests');
    }
};
