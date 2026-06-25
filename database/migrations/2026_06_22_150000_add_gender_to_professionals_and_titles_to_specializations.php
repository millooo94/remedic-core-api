<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            if (! Schema::hasColumn('professionals', 'gender')) {
                $table->string('gender', 32)
                    ->default('unspecified')
                    ->after('subject_type')
                    ->index();
            }
        });

        Schema::table('specializations', function (Blueprint $table): void {
            if (! Schema::hasColumn('specializations', 'professional_title_male')) {
                $table->string('professional_title_male')->nullable()->after('name');
            }

            if (! Schema::hasColumn('specializations', 'professional_title_female')) {
                $table->string('professional_title_female')->nullable()->after('professional_title_male');
            }
        });
    }

    public function down(): void
    {
        Schema::table('specializations', function (Blueprint $table): void {
            if (Schema::hasColumn('specializations', 'professional_title_female')) {
                $table->dropColumn('professional_title_female');
            }

            if (Schema::hasColumn('specializations', 'professional_title_male')) {
                $table->dropColumn('professional_title_male');
            }
        });

        Schema::table('professionals', function (Blueprint $table): void {
            if (Schema::hasColumn('professionals', 'gender')) {
                $table->dropColumn('gender');
            }
        });
    }
};
