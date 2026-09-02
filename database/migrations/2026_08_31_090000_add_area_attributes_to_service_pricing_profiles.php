<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_pricing_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_pricing_profiles', 'image_path')) {
                $table->string('image_path')->nullable()->after('label');
            }

            if (! Schema::hasColumn('service_pricing_profiles', 'is_ungrouped')) {
                $table->boolean('is_ungrouped')->default(false)->after('image_path');
            }
        });

        // "Nuovo profilo" is the untouched label emitted by the former
        // profile-based editor. A profile with that exact label and real
        // items is its deterministic legacy equivalent of an ungrouped
        // container; its pricing data remains on the same records.
        DB::table('service_pricing_profiles')
            ->where('label', 'Nuovo profilo')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('service_pricing_items')
                    ->whereColumn('service_pricing_items.service_pricing_profile_id', 'service_pricing_profiles.id');
            })
            ->update(['is_ungrouped' => true]);
    }

    public function down(): void
    {
        Schema::table('service_pricing_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('service_pricing_profiles', 'is_ungrouped')) {
                $table->dropColumn('is_ungrouped');
            }

            if (Schema::hasColumn('service_pricing_profiles', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });
    }
};
