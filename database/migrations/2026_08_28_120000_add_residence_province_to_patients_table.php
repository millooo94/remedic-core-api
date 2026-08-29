<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('patients', 'residence_province')) {
                $table->string('residence_province', 120)->nullable()->after('residence_city')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            if (Schema::hasColumn('patients', 'residence_province')) {
                $table->dropColumn('residence_province');
            }
        });
    }
};
