<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_provider_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_provider_accounts', 'config_json')) {
                $table->json('config_json')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('external_provider_accounts', 'last_test_at')) {
                $table->timestamp('last_test_at')->nullable()->after('last_appointment_sync_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_provider_accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('external_provider_accounts', 'last_test_at')) {
                $table->dropColumn('last_test_at');
            }

            if (Schema::hasColumn('external_provider_accounts', 'config_json')) {
                $table->dropColumn('config_json');
            }
        });
    }
};
