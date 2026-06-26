<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            $table->boolean('is_provvigione')->default(false)->after('is_promo');
        });
    }

    public function down(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            $table->dropColumn('is_provvigione');
        });
    }
};
