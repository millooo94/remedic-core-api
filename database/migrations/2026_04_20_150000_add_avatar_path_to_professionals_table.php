<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            if (! Schema::hasColumn('professionals', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('iban');
            }
        });
    }

    public function down(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            if (Schema::hasColumn('professionals', 'avatar_path')) {
                $table->dropColumn('avatar_path');
            }
        });
    }
};
