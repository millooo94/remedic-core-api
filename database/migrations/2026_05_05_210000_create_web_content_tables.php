<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('template')->default('default');
                $table->text('excerpt')->nullable();
                $table->text('intro_text')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->string('seo_h1')->nullable();
                $table->string('canonical_url')->nullable();
                $table->string('robots')->default('index,follow');
                $table->string('og_title')->nullable();
                $table->text('og_description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->index('is_active');
                $table->index('published_at');
                $table->index('template');
            });
        }

        if (! Schema::hasTable('blog_posts')) {
            Schema::create('blog_posts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('excerpt')->nullable();
                $table->string('cover_image')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->string('seo_h1')->nullable();
                $table->string('canonical_url')->nullable();
                $table->string('robots')->default('index,follow');
                $table->string('og_title')->nullable();
                $table->text('og_description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->index('is_active');
                $table->index('published_at');
            });
        }

        if (! Schema::hasTable('sections')) {
            Schema::create('sections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
                $table->morphs('sectionable');
                $table->string('key');
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->longText('content')->nullable();
                $table->json('extra_json')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('key');
                $table->index('sort_order');
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('faq_items')) {
            Schema::create('faq_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
                $table->morphs('faqable');
                $table->string('question');
                $table->text('answer');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_structured_data')->default(true);
                $table->timestamps();

                $table->index('sort_order');
                $table->index('is_active');
                $table->index('is_structured_data');
            });
        }

        if (! Schema::hasTable('redirects')) {
            Schema::create('redirects', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
                $table->string('from_path')->unique();
                $table->string('to_path');
                $table->unsignedSmallInteger('http_code')->default(301);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('is_active');
                $table->index('http_code');
            });
        }

        if (! Schema::hasTable('site_settings')) {
            Schema::create('site_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
                $table->string('site_name')->nullable();
                $table->string('site_url')->nullable();
                $table->string('brand_name')->nullable();
                $table->string('default_meta_title')->nullable();
                $table->text('default_meta_description')->nullable();
                $table->string('clinic_name')->nullable();
                $table->string('clinic_phone')->nullable();
                $table->string('clinic_email')->nullable();
                $table->string('clinic_address')->nullable();
                $table->string('clinic_city')->nullable();
                $table->string('primary_city')->nullable();
                $table->string('primary_area')->nullable();
                $table->json('served_areas')->nullable();
                $table->string('province_or_area_served')->nullable();
                $table->string('clinic_region')->nullable();
                $table->string('clinic_postal_code')->nullable();
                $table->string('clinic_country', 2)->default('IT');
                $table->string('google_maps_url')->nullable();
                $table->string('maps_url')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->text('area_served_text')->nullable();
                $table->string('default_locality_phrase')->nullable();
                $table->string('facebook_url')->nullable();
                $table->string('instagram_url')->nullable();
                $table->string('linkedin_url')->nullable();
                $table->string('whatsapp_number')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('default_og_image_path')->nullable();
                $table->json('opening_hours')->nullable();
                $table->string('vat_number')->nullable();
                $table->string('legal_company_name')->nullable();
                $table->string('business_type')->default('MedicalBusiness');
                $table->boolean('cmp_enabled')->default(true);
                $table->boolean('cmp_banner_enabled')->default(true);
                $table->string('cmp_consent_cookie_name')->default('remedic_consent');
                $table->unsignedInteger('cmp_consent_cookie_ttl_days')->default(180);
                $table->string('cmp_consent_storage_strategy')->default('cookie_uuid');
                $table->boolean('cmp_show_reject_all_button')->default(true);
                $table->boolean('cmp_show_accept_all_button')->default(true);
                $table->boolean('cmp_show_manage_preferences_button')->default(true);
                $table->boolean('cmp_respect_dnt_flag')->default(false);
                $table->boolean('cmp_consent_mode_enabled')->default(false);
                $table->boolean('cmp_auto_reprompt_on_policy_change')->default(true);
                $table->string('cmp_default_locale', 12)->default('it');
                $table->string('privacy_email')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('faq_items');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('pages');
    }
};
