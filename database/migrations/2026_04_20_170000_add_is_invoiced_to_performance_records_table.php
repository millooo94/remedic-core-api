<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'is_invoiced')) {
                $table->boolean('is_invoiced')->default(false)->after('center_amount')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'is_invoiced')) {
                $table->dropColumn('is_invoiced');
            }
        });
    }
};
