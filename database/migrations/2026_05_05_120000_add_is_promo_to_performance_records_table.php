<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'is_promo')) {
                $table->boolean('is_promo')->default(false)->index()->after('is_black');
            }
        });
    }

    public function down(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'is_promo')) {
                $table->dropColumn('is_promo');
            }
        });
    }
};
