<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'is_black')) {
                $table->boolean('is_black')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'is_black')) {
                $table->dropColumn('is_black');
            }
        });
    }
};

