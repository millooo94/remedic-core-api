<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consent_categories')) {
            Schema::create('consent_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('default_state')->default(false);
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('consent_services')) {
            Schema::create('consent_services', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('consent_category_id')->constrained('consent_categories')->cascadeOnDelete();
                $table->string('key')->unique();
                $table->string('name');
                $table->string('provider')->nullable();
                $table->text('description')->nullable();
                $table->text('purpose')->nullable();
                $table->string('privacy_url')->nullable();
                $table->json('cookie_names')->nullable();
                $table->string('retention_period')->nullable();
                $table->string('legal_basis_hint')->nullable();
                $table->string('execution_mode')->default('script');
                $table->json('public_config')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('consent_policy_versions')) {
            Schema::create('consent_policy_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('version')->unique();
                $table->string('banner_title')->nullable();
                $table->text('banner_text')->nullable();
                $table->string('preferences_title')->nullable();
                $table->text('preferences_text')->nullable();
                $table->foreignId('policy_page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->foreignId('cookie_policy_page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->foreignId('privacy_policy_page_id')->nullable()->constrained('pages')->nullOnDelete();
                $table->boolean('is_active')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->boolean('requires_reconsent')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('consent_records')) {
            Schema::create('consent_records', function (Blueprint $table): void {
                $table->id();
                $table->uuid('consent_uuid')->unique();
                $table->foreignId('consent_policy_version_id')->constrained('consent_policy_versions')->cascadeOnDelete();
                $table->string('locale', 12)->nullable();
                $table->string('source')->nullable();
                $table->boolean('necessary')->default(true);
                $table->boolean('preferences')->default(false);
                $table->boolean('analytics')->default(false);
                $table->boolean('marketing')->default(false);
                $table->timestamp('consented_at')->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('user_agent')->nullable();
                $table->string('ip_hash', 128)->nullable();
                $table->json('consent_version_snapshot')->nullable();
                $table->timestamps();

                $table->index(['consent_policy_version_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('consent_preference_changes')) {
            Schema::create('consent_preference_changes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('consent_record_id')->constrained('consent_records')->cascadeOnDelete();
                $table->string('event_type');
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['consent_record_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_preference_changes');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('consent_policy_versions');
        Schema::dropIfExists('consent_services');
        Schema::dropIfExists('consent_categories');
    }
};
