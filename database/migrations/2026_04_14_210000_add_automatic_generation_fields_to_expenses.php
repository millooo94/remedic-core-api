<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_templates', function (Blueprint $table): void {
            $table->unsignedTinyInteger('day_of_generation')->nullable()->after('end_date');
        });

        Schema::table('expense_records', function (Blueprint $table): void {
            $table->enum('source', ['manual', 'automatic'])->default('manual')->after('expense_template_id')->index();
            $table->string('generation_key')->nullable()->after('source');
            $table->unique('generation_key');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE expense_templates MODIFY recurrence ENUM('weekly','monthly','bimonthly','quarterly','yearly','manual') NOT NULL DEFAULT 'monthly'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE expense_templates MODIFY recurrence ENUM('monthly','bimonthly','quarterly','yearly','manual') NOT NULL DEFAULT 'monthly'");
        }

        Schema::table('expense_records', function (Blueprint $table): void {
            $table->dropUnique(['generation_key']);
            $table->dropColumn(['source', 'generation_key']);
        });

        Schema::table('expense_templates', function (Blueprint $table): void {
            $table->dropColumn('day_of_generation');
        });
    }
};

