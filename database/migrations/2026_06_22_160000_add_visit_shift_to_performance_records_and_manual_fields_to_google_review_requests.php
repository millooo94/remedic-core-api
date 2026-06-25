<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'visit_shift')) {
                $table->string('visit_shift', 32)
                    ->default('morning')
                    ->after('performed_at')
                    ->index();
            }
        });

        Schema::table('google_review_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('google_review_requests', 'manual_override')) {
                $table->boolean('manual_override')
                    ->default(false)
                    ->after('scheduled_at')
                    ->index();
            }

            if (! Schema::hasColumn('google_review_requests', 'manual_override_at')) {
                $table->timestamp('manual_override_at')
                    ->nullable()
                    ->after('manual_override');
            }

            if (! Schema::hasColumn('google_review_requests', 'manual_override_by')) {
                $table->foreignId('manual_override_by')
                    ->nullable()
                    ->after('manual_override_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('google_review_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')
                    ->nullable()
                    ->after('excluded_at');
            }

            if (! Schema::hasColumn('google_review_requests', 'cancelled_by')) {
                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->after('cancelled_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('google_review_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('google_review_requests', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }

            if (Schema::hasColumn('google_review_requests', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }

            if (Schema::hasColumn('google_review_requests', 'manual_override_by')) {
                $table->dropConstrainedForeignId('manual_override_by');
            }

            if (Schema::hasColumn('google_review_requests', 'manual_override_at')) {
                $table->dropColumn('manual_override_at');
            }

            if (Schema::hasColumn('google_review_requests', 'manual_override')) {
                $table->dropColumn('manual_override');
            }
        });

        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'visit_shift')) {
                $table->dropColumn('visit_shift');
            }
        });
    }
};
