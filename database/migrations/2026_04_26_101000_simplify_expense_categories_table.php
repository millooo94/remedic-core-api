<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('expense_categories', 'type')) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS expense_categories_type_index');
            } else {
                Schema::table('expense_categories', function (Blueprint $table): void {
                    $table->dropIndex('expense_categories_type_index');
                });
            }
        }

        Schema::table('expense_categories', function (Blueprint $table): void {
            if (Schema::hasColumn('expense_categories', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('expense_categories', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('expense_categories', 'type')) {
                $table->enum('type', ['fixed', 'variable'])->default('variable')->index();
            }

            if (! Schema::hasColumn('expense_categories', 'sort_order')) {
                $table->unsignedInteger('sort_order')->nullable();
            }
        });
    }
};
