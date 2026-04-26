<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_service_categories', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->nullable()->after('service_category_id');
        });

        $linksByProfessional = DB::table('professional_service_categories')
            ->select(['id', 'professional_id'])
            ->orderBy('professional_id')
            ->orderBy('id')
            ->get()
            ->groupBy('professional_id');

        foreach ($linksByProfessional as $links) {
            foreach ($links->values() as $index => $link) {
                DB::table('professional_service_categories')
                    ->where('id', (int) $link->id)
                    ->update(['sort_order' => $index]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('professional_service_categories', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
