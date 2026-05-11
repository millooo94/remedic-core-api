<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specializations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->text('intro_text')->nullable();
            $table->text('local_intro_text')->nullable();
            $table->text('local_area_notes')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('local_seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('local_seo_description')->nullable();
            $table->string('seo_h1')->nullable();
            $table->string('local_seo_h1')->nullable();
            $table->boolean('is_local_seo_enabled')->default(true);
            $table->string('canonical_url')->nullable();
            $table->string('robots')->default('index,follow');
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('professional_public_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('legacy_backend_id')->nullable()->unique();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title_prefix')->nullable();
            $table->text('short_bio')->nullable();
            $table->string('registration_number')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('profile_image_path')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_h1')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('robots')->default('index,follow');
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique('professional_id');
        });

        Schema::create('professional_degrees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->string('title');
            $table->date('awarded_on')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('professional_academic_specializations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->string('title');
            $table->date('awarded_on')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('professional_board_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->string('board_name');
            $table->date('registered_on')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('professional_specialization', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->foreignId('specialization_id')->constrained('specializations')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(['professional_id', 'specialization_id'], 'professional_specialization_unique');
        });

        Schema::create('service_specialization', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('specialization_id')->constrained('specializations')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique(['service_id', 'specialization_id'], 'service_specialization_unique');
        });

        Schema::table('professional_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('professional_services', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_bookable_online');
            }

            if (! Schema::hasColumn('professional_services', 'editorial_notes')) {
                $table->text('editorial_notes')->nullable()->after('source_notes');
            }

            if (! Schema::hasColumn('professional_services', 'public_sort_order')) {
                $table->integer('public_sort_order')->default(0)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('professional_services', function (Blueprint $table): void {
            if (Schema::hasColumn('professional_services', 'public_sort_order')) {
                $table->dropColumn('public_sort_order');
            }

            if (Schema::hasColumn('professional_services', 'editorial_notes')) {
                $table->dropColumn('editorial_notes');
            }

            if (Schema::hasColumn('professional_services', 'is_featured')) {
                $table->dropColumn('is_featured');
            }
        });

        Schema::dropIfExists('service_specialization');
        Schema::dropIfExists('professional_specialization');
        Schema::dropIfExists('professional_board_registrations');
        Schema::dropIfExists('professional_academic_specializations');
        Schema::dropIfExists('professional_degrees');
        Schema::dropIfExists('professional_public_profiles');
        Schema::dropIfExists('specializations');
    }
};
