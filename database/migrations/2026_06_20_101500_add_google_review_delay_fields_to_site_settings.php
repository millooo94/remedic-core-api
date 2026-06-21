<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('site_settings', 'google_review_delay_days')) {
                $table->unsignedSmallInteger('google_review_delay_days')->default(3)->after('google_review_url');
            }

            if (! Schema::hasColumn('site_settings', 'google_review_delay_hours')) {
                $table->unsignedSmallInteger('google_review_delay_hours')->default(0)->after('google_review_delay_days');
            }

            if (! Schema::hasColumn('site_settings', 'google_review_delay_minutes')) {
                $table->unsignedSmallInteger('google_review_delay_minutes')->default(0)->after('google_review_delay_hours');
            }

            if (! Schema::hasColumn('site_settings', 'google_review_delay_seconds')) {
                $table->unsignedSmallInteger('google_review_delay_seconds')->default(0)->after('google_review_delay_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $columns = [
                'google_review_delay_days',
                'google_review_delay_hours',
                'google_review_delay_minutes',
                'google_review_delay_seconds',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('site_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
