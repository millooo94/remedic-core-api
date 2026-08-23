<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('external_provider_login_sessions');
        Schema::dropIfExists('external_provider_professionals');
        Schema::dropIfExists('external_provider_accounts');
        Schema::dropIfExists('google_review_requests');

        if (Schema::hasTable('site_settings')) {
            Schema::table('site_settings', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    [
                        'google_review_delay_days',
                        'google_review_delay_hours',
                        'google_review_delay_minutes',
                        'google_review_delay_seconds',
                    ],
                    fn (string $column): bool => Schema::hasColumn('site_settings', $column),
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('professional_availability_rules')) {
            if (Schema::hasColumn('professional_availability_rules', 'source')) {
                DB::table('professional_availability_rules')->where('source', 'miodottore')->delete();
            }

            Schema::table('professional_availability_rules', function (Blueprint $table): void {
                if (Schema::hasColumn('professional_availability_rules', 'source')) {
                    $table->dropIndex('pa_rules_prof_source_idx');
                    $table->dropIndex('pa_rules_source_idx');
                }

                $columns = array_values(array_filter(
                    ['source', 'external_hash', 'last_synced_at'],
                    fn (string $column): bool => Schema::hasColumn('professional_availability_rules', $column),
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('professional_availability_exceptions')) {
            if (Schema::hasColumn('professional_availability_exceptions', 'source')) {
                DB::table('professional_availability_exceptions')->where('source', 'miodottore')->delete();
            }

            Schema::table('professional_availability_exceptions', function (Blueprint $table): void {
                if (Schema::hasColumn('professional_availability_exceptions', 'source')) {
                    $table->dropIndex('pa_exc_prof_source_idx');
                    $table->dropIndex('pa_exc_source_idx');
                }

                $columns = array_values(array_filter(
                    ['source', 'external_hash', 'last_synced_at'],
                    fn (string $column): bool => Schema::hasColumn('professional_availability_exceptions', $column),
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('marketing_campaigns')) {
            Schema::table('marketing_campaigns', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    [
                        'whatsapp_image_path',
                        'whatsapp_image_original_name',
                        'whatsapp_image_mime_type',
                        'whatsapp_image_size',
                    ],
                    fn (string $column): bool => Schema::hasColumn('marketing_campaigns', $column),
                ));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('google_review_delay_days')->default(3)->after('google_review_url');
            $table->unsignedSmallInteger('google_review_delay_hours')->default(0)->after('google_review_delay_days');
            $table->unsignedSmallInteger('google_review_delay_minutes')->default(0)->after('google_review_delay_hours');
            $table->unsignedSmallInteger('google_review_delay_seconds')->default(0)->after('google_review_delay_minutes');
        });

        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            $table->string('whatsapp_image_path')->nullable()->after('message');
            $table->string('whatsapp_image_original_name', 190)->nullable()->after('whatsapp_image_path');
            $table->string('whatsapp_image_mime_type', 80)->nullable()->after('whatsapp_image_original_name');
            $table->unsignedBigInteger('whatsapp_image_size')->nullable()->after('whatsapp_image_mime_type');
        });

        Schema::table('professional_availability_rules', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('professional_id');
            $table->string('external_hash')->nullable()->after('notes');
            $table->timestamp('last_synced_at')->nullable()->after('external_hash');
            $table->index(['professional_id', 'source'], 'pa_rules_prof_source_idx');
            $table->index('source', 'pa_rules_source_idx');
        });

        Schema::table('professional_availability_exceptions', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('professional_id');
            $table->string('external_hash')->nullable()->after('reason');
            $table->timestamp('last_synced_at')->nullable()->after('external_hash');
            $table->index(['professional_id', 'source'], 'pa_exc_prof_source_idx');
            $table->index('source', 'pa_exc_source_idx');
        });

        Schema::create('external_provider_professionals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('external_name')->nullable();
            $table->string('external_id')->nullable();
            $table->text('external_url')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 50)->default('never_synced');
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
            $table->unique(['professional_id', 'provider'], 'ext_provider_prof_unique');
            $table->index(['provider', 'enabled'], 'ext_provider_enabled_idx');
            $table->index('sync_status', 'ext_provider_sync_status_idx');
        });

        Schema::create('external_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 80)->unique();
            $table->string('label', 120);
            $table->boolean('enabled')->default(false);
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('storage_state_path')->nullable();
            $table->string('login_status', 40)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_session_verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_availability_sync_at')->nullable();
            $table->timestamp('last_patient_sync_at')->nullable();
            $table->timestamp('last_appointment_sync_at')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('config_json')->nullable();
            $table->timestamps();
        });

        Schema::create('external_provider_login_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 80);
            $table->string('token', 120)->unique();
            $table->string('status', 40)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('artifacts_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['provider', 'status']);
        });

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
            $table->boolean('manual_override')->default(false)->index();
            $table->timestamp('manual_override_at')->nullable();
            $table->foreignId('manual_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
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
};
