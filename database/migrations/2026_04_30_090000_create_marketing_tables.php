<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name')->index();
            $table->unsignedSmallInteger('year_of_birth')->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('whatsapp_phone')->nullable()->index();
            $table->boolean('contactable_sms')->default(true)->index();
            $table->boolean('contactable_whatsapp')->default(true)->index();
            $table->boolean('contactable_email')->default(true)->index();
            $table->boolean('excluded_from_campaigns')->default(false)->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'patient_id')) {
                $table->foreignId('patient_id')
                    ->nullable()
                    ->after('performed_at')
                    ->constrained('patients')
                    ->nullOnDelete();
            }
        });

        Schema::create('marketing_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('filters');
            $table->unsignedInteger('last_preview_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('marketing_segment_id')->constrained('marketing_segments')->restrictOnDelete();
            $table->enum('channel', ['sms', 'whatsapp', 'email'])->index();
            $table->string('template_key')->nullable();
            $table->string('subject')->nullable();
            $table->text('message');
            $table->enum('status', ['draft', 'scheduled', 'queued', 'sending', 'sent', 'partial_failed', 'failed'])->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->timestamp('last_test_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('launched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_campaign_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->enum('channel', ['sms', 'whatsapp', 'email'])->index();
            $table->boolean('is_test')->default(false)->index();
            $table->string('target_name')->nullable();
            $table->string('target_value');
            $table->enum('delivery_status', ['pending', 'sent', 'failed', 'excluded'])->default('pending')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_status')->nullable();
            $table->text('error_message')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_deliveries');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('marketing_segments');

        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'patient_id')) {
                $table->dropConstrainedForeignId('patient_id');
            }
        });

        Schema::dropIfExists('patients');
    }
};
