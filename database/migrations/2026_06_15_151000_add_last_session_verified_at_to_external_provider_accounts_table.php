<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_provider_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_provider_accounts', 'last_session_verified_at')) {
                $table->timestamp('last_session_verified_at')->nullable()->after('last_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_provider_accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('external_provider_accounts', 'last_session_verified_at')) {
                $table->dropColumn('last_session_verified_at');
            }
        });
    }
};
