<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specializations', function (Blueprint $table): void {
            $table->string('color_hex', 7)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('specializations', function (Blueprint $table): void {
            $table->dropColumn('color_hex');
        });
    }
};
