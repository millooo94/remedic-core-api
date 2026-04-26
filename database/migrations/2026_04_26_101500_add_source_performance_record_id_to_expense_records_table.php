<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('expense_records', 'source_performance_record_id')) {
                $table->foreignId('source_performance_record_id')
                    ->nullable()
                    ->after('expense_template_id')
                    ->constrained('performance_records')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();

                $table->unique('source_performance_record_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('expense_records', function (Blueprint $table): void {
            if (Schema::hasColumn('expense_records', 'source_performance_record_id')) {
                $table->dropUnique('expense_records_source_performance_record_id_unique');
                $table->dropForeign(['source_performance_record_id']);
                $table->dropColumn('source_performance_record_id');
            }
        });
    }
};

