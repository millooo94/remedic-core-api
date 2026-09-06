<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sections', 'internal_title')) {
            Schema::table('sections', function (Blueprint $table): void {
                $table->string('internal_title')->nullable()->after('template');
            });
        }

        DB::table('sections')->whereNull('internal_title')->update(['internal_title' => DB::raw('title')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sections', 'internal_title')) {
            Schema::table('sections', function (Blueprint $table): void {
                $table->dropColumn('internal_title');
            });
        }
    }
};
