<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_degrees', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_degrees', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('professional_academic_specializations', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_academic_specializations', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('professional_board_registrations', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_board_registrations', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('professional_board_registrations', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_board_registrations', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });

        Schema::table('professional_academic_specializations', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_academic_specializations', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });

        Schema::table('professional_degrees', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_degrees', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });
    }
};
