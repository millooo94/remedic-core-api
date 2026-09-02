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
            if (! Schema::hasColumn('services', 'classification')) {
                // Existing records without an exclusive type stay explicitly
                // unclassified. Only the structured legacy diagnostic flag is
                // deterministic enough for the backfill below.
                $table->string('classification', 32)->nullable()->index()->after('category_id');
            }
        });

        DB::table('services')->where('is_diagnostic', true)->update(['classification' => 'diagnostic']);

        Schema::table('service_pricing_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_pricing_items', 'recipient')) {
                $table->string('recipient', 16)->default('unspecified')->after('kind');
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_pricing_items', function (Blueprint $table): void {
            if (Schema::hasColumn('service_pricing_items', 'recipient')) {
                $table->dropColumn('recipient');
            }
        });

        Schema::table('services', function (Blueprint $table): void {
            if (Schema::hasColumn('services', 'classification')) {
                $table->dropIndex(['classification']);
                $table->dropColumn('classification');
            }
        });
    }
};
