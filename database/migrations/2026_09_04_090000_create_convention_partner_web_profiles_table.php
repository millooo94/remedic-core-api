<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convention_partner_web_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('convention_partner_id')->unique();
            $table->foreign('convention_partner_id', 'convention_partner_web_profiles_partner_fk')->references('id')->on('convention_partners')->restrictOnDelete();
            $table->boolean('is_web_enabled')->default(false)->index();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_h1')->nullable();
            $table->string('local_seo_title')->nullable();
            $table->text('local_seo_description')->nullable();
            $table->string('local_seo_h1')->nullable();
            $table->boolean('is_local_seo_enabled')->default(true);
            $table->string('canonical_url')->nullable();
            $table->string('robots')->default('noindex,nofollow');
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image_path')->nullable();
            $table->string('twitter_title')->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convention_partner_web_profiles');
    }
};
