<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('professionals', function (Blueprint $table): void {
            if (! Schema::hasColumn('professionals', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });

        Schema::table('professional_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_services', 'legacy_backend_id')) {
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_services', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });

        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });

        Schema::table('professionals', function (Blueprint $table): void {
            if (Schema::hasColumn('professionals', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'legacy_backend_id')) {
                $table->dropColumn('legacy_backend_id');
            }
        });
    }
};
