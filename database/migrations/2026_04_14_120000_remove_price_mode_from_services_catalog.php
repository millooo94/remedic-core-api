<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_services', 'price_amount')) {
                $table->decimal('price_amount', 10, 2)->nullable()->after('duration_minutes');
            }
        });

        if (
            Schema::hasColumn('professional_services', 'fixed_price')
            || Schema::hasColumn('professional_services', 'starting_price')
        ) {
            DB::statement('
                UPDATE professional_services
                SET price_amount = COALESCE(fixed_price, starting_price)
                WHERE price_amount IS NULL
            ');
        }

        Schema::table('professional_services', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_services', 'price_mode')) {
                // SQLite keeps stale index metadata on dropped columns unless index is removed first.
                DB::statement('DROP INDEX IF EXISTS professional_services_price_mode_index');
            }
            if (Schema::hasColumn('professional_services', 'price_mode')) {
                $table->dropColumn('price_mode');
            }
            if (Schema::hasColumn('professional_services', 'fixed_price')) {
                $table->dropColumn('fixed_price');
            }
            if (Schema::hasColumn('professional_services', 'starting_price')) {
                $table->dropColumn('starting_price');
            }
        });

        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'needs_manual_price')) {
                $table->dropColumn('needs_manual_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'needs_manual_price')) {
                $table->boolean('needs_manual_price')->default(false)->after('is_active');
            }
        });

        Schema::table('professional_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_services', 'price_mode')) {
                $table->enum('price_mode', ['fixed', 'starting_from', 'missing'])->default('missing')->index();
            }
            if (! Schema::hasColumn('professional_services', 'fixed_price')) {
                $table->decimal('fixed_price', 10, 2)->nullable()->after('price_mode');
            }
            if (! Schema::hasColumn('professional_services', 'starting_price')) {
                $table->decimal('starting_price', 10, 2)->nullable()->after('fixed_price');
            }
        });

        DB::statement("
            UPDATE professional_services
            SET
                fixed_price = price_amount,
                starting_price = NULL,
                price_mode = CASE
                    WHEN price_amount IS NULL THEN 'missing'
                    ELSE 'fixed'
                END
        ");

        Schema::table('professional_services', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_services', 'price_amount')) {
                $table->dropColumn('price_amount');
            }
        });
    }
};
