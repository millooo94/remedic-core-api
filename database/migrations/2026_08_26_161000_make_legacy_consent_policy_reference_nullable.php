<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_records', function (Blueprint $table): void {
            $table->foreignId('consent_policy_version_id')->nullable()->change();
        });
    }

    public function down(): void {}
};
