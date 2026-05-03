<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_records', function (Blueprint $table): void {
            $table->index('source_performance_record_id', 'expense_records_source_performance_record_id_index');
            $table->dropUnique('expense_records_source_performance_record_id_unique');
            $table->unique(['source_performance_record_id', 'generation_key'], 'expense_records_source_performance_generation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expense_records', function (Blueprint $table): void {
            $table->dropUnique('expense_records_source_performance_generation_unique');
            $table->unique('source_performance_record_id', 'expense_records_source_performance_record_id_unique');
            $table->dropIndex('expense_records_source_performance_record_id_index');
        });
    }
};
