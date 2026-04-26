<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('cash_movements', 'source_performance_record_id')) {
                $table->foreignId('source_performance_record_id')
                    ->nullable()
                    ->after('notes')
                    ->constrained('performance_records')
                    ->restrictOnDelete();

                $table->unique('source_performance_record_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('cash_movements', 'source_performance_record_id')) {
                $table->dropUnique('cash_movements_source_performance_record_id_unique');
                $table->dropForeign(['source_performance_record_id']);
                $table->dropColumn('source_performance_record_id');
            }
        });
    }
};
