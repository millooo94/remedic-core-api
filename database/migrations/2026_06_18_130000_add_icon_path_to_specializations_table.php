<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specializations', function (Blueprint $table): void {
            if (! Schema::hasColumn('specializations', 'icon_path')) {
                $table->string('icon_path')->nullable()->after('color_hex');
            }
        });
    }

    public function down(): void
    {
        Schema::table('specializations', function (Blueprint $table): void {
            if (Schema::hasColumn('specializations', 'icon_path')) {
                $table->dropColumn('icon_path');
            }
        });
    }
};
