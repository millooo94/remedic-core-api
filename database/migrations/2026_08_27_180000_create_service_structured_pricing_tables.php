<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_pricing_profiles')) {
            Schema::create('service_pricing_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_id');
                $table->foreign('service_id', 'svc_price_profile_service_fk')->references('id')->on('services')->cascadeOnDelete();
                $table->string('label');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['service_id', 'sort_order'], 'svc_price_profile_order_idx');
            });
        }
        if (! Schema::hasTable('service_pricing_items')) {
            Schema::create('service_pricing_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_pricing_profile_id');
                $table->foreign('service_pricing_profile_id', 'svc_price_item_profile_fk')->references('id')->on('service_pricing_profiles')->cascadeOnDelete();
                $table->string('label');
                $table->string('kind', 32);
                $table->decimal('price_amount', 10, 2);
                $table->text('business_note')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['service_pricing_profile_id', 'sort_order'], 'svc_price_item_profile_order_idx');
            });
        }
        if (! Schema::hasTable('service_pricing_profile_presentations')) {
            Schema::create('service_pricing_profile_presentations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_pricing_profile_id');
                $table->unique('service_pricing_profile_id', 'svc_price_profile_presentation_unique');
                $table->foreign('service_pricing_profile_id', 'svc_price_profile_presentation_fk')->references('id')->on('service_pricing_profiles')->cascadeOnDelete();
                $table->string('public_label')->nullable();
                $table->text('intro')->nullable();
                $table->boolean('is_web_enabled')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('service_pricing_item_presentations')) {
            Schema::create('service_pricing_item_presentations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_pricing_item_id');
                $table->unique('service_pricing_item_id', 'svc_price_item_presentation_unique');
                $table->foreign('service_pricing_item_id', 'svc_price_item_presentation_fk')->references('id')->on('service_pricing_items')->cascadeOnDelete();
                $table->string('icon_path')->nullable();
                $table->string('public_label')->nullable();
                $table->text('public_note')->nullable();
                $table->boolean('is_highlighted')->default(false);
                $table->boolean('is_web_enabled')->default(false);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('service_pricing_presentation_translations')) {
            Schema::create('service_pricing_presentation_translations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('service_pricing_profile_presentation_id')->nullable();
                $table->foreign('service_pricing_profile_presentation_id', 'svc_price_profile_translation_fk')->references('id')->on('service_pricing_profile_presentations')->cascadeOnDelete();
                $table->unsignedBigInteger('service_pricing_item_presentation_id')->nullable();
                $table->foreign('service_pricing_item_presentation_id', 'svc_price_item_translation_fk')->references('id')->on('service_pricing_item_presentations')->cascadeOnDelete();
                $table->string('locale', 8);
                $table->string('label')->nullable();
                $table->text('note')->nullable();
                $table->string('publication_state', 24)->default('draft');
                $table->string('source_revision', 64)->nullable();
                $table->string('reviewed_source_revision', 64)->nullable();
                $table->timestamps();
                $table->unique(['service_pricing_profile_presentation_id', 'locale'], 'service_pricing_profile_presentation_locale_unique');
                $table->unique(['service_pricing_item_presentation_id', 'locale'], 'service_pricing_item_presentation_locale_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_pricing_presentation_translations');
        Schema::dropIfExists('service_pricing_item_presentations');
        Schema::dropIfExists('service_pricing_profile_presentations');
        Schema::dropIfExists('service_pricing_items');
        Schema::dropIfExists('service_pricing_profiles');
    }
};
