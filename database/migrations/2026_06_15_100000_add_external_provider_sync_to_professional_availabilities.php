<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_availability_rules', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('professional_id');
            $table->string('external_hash')->nullable()->after('notes');
            $table->timestamp('last_synced_at')->nullable()->after('external_hash');
            $table->index(['professional_id', 'source'], 'pa_rules_prof_source_idx');
            $table->index('source', 'pa_rules_source_idx');
        });

        Schema::table('professional_availability_exceptions', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('professional_id');
            $table->string('external_hash')->nullable()->after('reason');
            $table->timestamp('last_synced_at')->nullable()->after('external_hash');
            $table->index(['professional_id', 'source'], 'pa_exc_prof_source_idx');
            $table->index('source', 'pa_exc_source_idx');
        });

        Schema::create('external_provider_professionals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('external_name')->nullable();
            $table->string('external_id')->nullable();
            $table->text('external_url')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 50)->default('never_synced');
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->unique(['professional_id', 'provider'], 'ext_provider_prof_unique');
            $table->index(['provider', 'enabled'], 'ext_provider_enabled_idx');
            $table->index('sync_status', 'ext_provider_sync_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_provider_professionals');

        Schema::table('professional_availability_exceptions', function (Blueprint $table): void {
            $table->dropIndex('pa_exc_prof_source_idx');
            $table->dropIndex('pa_exc_source_idx');
            $table->dropColumn(['source', 'external_hash', 'last_synced_at']);
        });

        Schema::table('professional_availability_rules', function (Blueprint $table): void {
            $table->dropIndex('pa_rules_prof_source_idx');
            $table->dropIndex('pa_rules_source_idx');
            $table->dropColumn(['source', 'external_hash', 'last_synced_at']);
        });
    }
};
