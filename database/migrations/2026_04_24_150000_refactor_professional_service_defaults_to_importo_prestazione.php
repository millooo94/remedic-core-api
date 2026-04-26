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
            $table->decimal('importo_prestazione', 10, 2)->nullable()->after('price_amount');
        });

        DB::table('professional_services')
            ->whereNotNull('prezzo_standard')
            ->update(['importo_prestazione' => DB::raw('prezzo_standard')]);

        Schema::table('professional_services', function (Blueprint $table): void {
            $table->dropColumn(['prezzo_standard', 'percentuale_standard_medico']);
        });
    }

    public function down(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            $table->decimal('prezzo_standard', 10, 2)->nullable()->after('price_amount');
            $table->decimal('percentuale_standard_medico', 5, 2)->nullable()->after('prezzo_standard');
        });

        DB::table('professional_services')
            ->whereNotNull('importo_prestazione')
            ->update(['prezzo_standard' => DB::raw('importo_prestazione')]);

        Schema::table('professional_services', function (Blueprint $table): void {
            $table->dropColumn('importo_prestazione');
        });
    }
};
