<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'sections_owner_key_unique';

    public function up(): void
    {
        $duplicatesExist = DB::table('sections')
            ->select('sectionable_type', 'sectionable_id', 'key')
            ->groupBy('sectionable_type', 'sectionable_id', 'key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException('Impossibile applicare il vincolo univoco sections: esistono chiavi duplicate per lo stesso contenuto.');
        }

        Schema::table('sections', function (Blueprint $table): void {
            $table->unique(['sectionable_type', 'sectionable_id', 'key'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX_NAME);
        });
    }
};
