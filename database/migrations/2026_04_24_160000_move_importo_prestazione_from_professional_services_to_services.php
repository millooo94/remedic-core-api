<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'importo_prestazione')) {
                $table->decimal('importo_prestazione', 10, 2)->nullable()->after('display_name');
            }
        });

        if (Schema::hasColumn('professional_services', 'importo_prestazione')) {
            DB::statement(<<<'SQL'
                UPDATE services
                SET importo_prestazione = (
                    SELECT MAX(professional_services.importo_prestazione)
                    FROM professional_services
                    WHERE professional_services.service_id = services.id
                      AND professional_services.importo_prestazione IS NOT NULL
                )
                WHERE importo_prestazione IS NULL
            SQL);

            Schema::table('professional_services', function (Blueprint $table): void {
                $table->dropColumn('importo_prestazione');
            });
        }
    }

    public function down(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_services', 'importo_prestazione')) {
                $table->decimal('importo_prestazione', 10, 2)->nullable()->after('price_amount');
            }
        });

        if (Schema::hasColumn('services', 'importo_prestazione')) {
            DB::statement(<<<'SQL'
                UPDATE professional_services
                SET importo_prestazione = (
                    SELECT services.importo_prestazione
                    FROM services
                    WHERE services.id = professional_services.service_id
                )
                WHERE EXISTS (
                    SELECT 1
                    FROM services
                    WHERE services.id = professional_services.service_id
                      AND services.importo_prestazione IS NOT NULL
                )
            SQL);

            Schema::table('services', function (Blueprint $table): void {
                $table->dropColumn('importo_prestazione');
            });
        }
    }
};
