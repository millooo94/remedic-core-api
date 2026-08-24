<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialization_web_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('specialization_id')->unique();
            $table->foreign('specialization_id', 'swp_specialization_fk')
                ->references('id')->on('specializations')->restrictOnDelete();
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->boolean('is_web_enabled')->default(false)->index();
            $table->integer('list_sort_order')->default(0)->index();
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
            $table->json('legacy_content')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specialization_web_profiles');
    }
};
