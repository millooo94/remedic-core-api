<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_configurations', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestamps();
        });
        Schema::table('consent_records', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('consent_uuid');
            $table->string('management_token_hash', 64)->nullable()->after('public_id');
            $table->unsignedInteger('configuration_version')->default(1)->after('management_token_hash')->index();
            $table->boolean('statistics')->default(false)->after('preferences');
            $table->timestamp('last_updated_at')->nullable()->after('consented_at');
        });
        Schema::table('consent_preference_changes', function (Blueprint $table): void {
            $table->unsignedInteger('configuration_version')->default(1)->after('event_type')->index();
            $table->boolean('necessary')->default(true)->after('configuration_version');
            $table->boolean('preferences')->default(false)->after('necessary');
            $table->boolean('statistics')->default(false)->after('preferences');
            $table->boolean('marketing')->default(false)->after('statistics');
            $table->timestamp('occurred_at')->nullable()->after('marketing')->index();
        });
        DB::table('consent_configurations')->insert(['id' => 1, 'is_enabled' => false, 'configuration_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_settings')->where('id', 1)->update(['cmp_enabled' => false, 'cmp_banner_enabled' => false, 'cmp_consent_mode_enabled' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('consent_preference_changes', function (Blueprint $table): void {
            $table->dropIndex(['occurred_at']);
            $table->dropIndex(['configuration_version']);
            $table->dropColumn(['configuration_version', 'necessary', 'preferences', 'statistics', 'marketing', 'occurred_at']);
        });
        Schema::table('consent_records', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropIndex(['configuration_version']);
            $table->dropColumn(['public_id', 'management_token_hash', 'configuration_version', 'statistics', 'last_updated_at']);
        });
        Schema::dropIfExists('consent_configurations');
    }
};
