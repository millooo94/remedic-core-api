<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'payment_method')) {
                $table->enum('payment_method', ['cash', 'card'])->default('card')->index()->after('center_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
        });
    }
};
