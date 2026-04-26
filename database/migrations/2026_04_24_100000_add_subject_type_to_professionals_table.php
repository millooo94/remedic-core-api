<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            if (! Schema::hasColumn('professionals', 'subject_type')) {
                $table->string('subject_type', 20)->default('individual')->after('id')->index();
            }

            if (! Schema::hasColumn('professionals', 'company_name')) {
                $table->string('company_name')->nullable()->after('last_name');
            }
        });

        DB::table('professionals')
            ->whereNull('subject_type')
            ->update(['subject_type' => 'individual']);

        Schema::table('professionals', function (Blueprint $table): void {
            if (Schema::hasColumn('professionals', 'first_name')) {
                $table->string('first_name')->nullable()->change();
            }

            if (Schema::hasColumn('professionals', 'last_name')) {
                $table->string('last_name')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        DB::table('professionals')
            ->whereNull('first_name')
            ->update(['first_name' => '']);

        DB::table('professionals')
            ->whereNull('last_name')
            ->update(['last_name' => '']);

        Schema::table('professionals', function (Blueprint $table): void {
            if (Schema::hasColumn('professionals', 'first_name')) {
                $table->string('first_name')->nullable(false)->change();
            }

            if (Schema::hasColumn('professionals', 'last_name')) {
                $table->string('last_name')->nullable(false)->change();
            }

            if (Schema::hasColumn('professionals', 'company_name')) {
                $table->dropColumn('company_name');
            }

            if (Schema::hasColumn('professionals', 'subject_type')) {
                $table->dropColumn('subject_type');
            }
        });
    }
};
