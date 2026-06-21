<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('pages', 'internal_key')) {
                $table->string('internal_key')->nullable()->after('legacy_backend_id');
            }
        });

        DB::table('pages')
            ->whereNull('internal_key')
            ->update([
                'internal_key' => DB::raw('slug'),
            ]);

        Schema::table('pages', function (Blueprint $table): void {
            $table->unique('internal_key');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'internal_key')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropUnique(['internal_key']);
            $table->dropColumn('internal_key');
        });
    }
};
