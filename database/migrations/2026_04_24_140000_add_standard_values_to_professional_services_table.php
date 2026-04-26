<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            $table->decimal('prezzo_standard', 10, 2)->nullable()->after('price_amount');
            $table->decimal('percentuale_standard_medico', 5, 2)->nullable()->after('prezzo_standard');
        });
    }

    public function down(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            $table->dropColumn(['prezzo_standard', 'percentuale_standard_medico']);
        });
    }
};
