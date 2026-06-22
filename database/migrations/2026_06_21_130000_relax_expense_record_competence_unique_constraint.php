<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_record_competences', function (Blueprint $table): void {
            $table->index('expense_record_id', 'expense_record_competences_record_fk_idx');
            $table->dropUnique('expense_record_competences_unique_month');
            $table->index(
                ['expense_record_id', 'competence_year', 'competence_month'],
                'expense_record_competences_record_month_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('expense_record_competences', function (Blueprint $table): void {
            $table->dropIndex('expense_record_competences_record_month_idx');
            $table->unique(
                ['expense_record_id', 'competence_year', 'competence_month'],
                'expense_record_competences_unique_month',
            );
            $table->dropIndex('expense_record_competences_record_fk_idx');
        });
    }
};
