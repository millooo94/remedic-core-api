<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->string('subtitle')->nullable()->after('slug');
            $table->string('category_label')->nullable()->after('subtitle');
            $table->text('intro_text')->nullable()->after('excerpt');
            $table->string('author_name')->nullable()->after('cover_image');
            $table->string('reviewer_name')->nullable()->after('author_name');
        });

        Schema::create('blog_post_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blog_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['blog_post_id', 'service_id']);
            $table->index(['blog_post_id', 'sort_order']);
        });

        Schema::create('blog_post_related_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('related_blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['blog_post_id', 'related_blog_post_id']);
            $table->index(['blog_post_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_related_posts');
        Schema::dropIfExists('blog_post_services');

        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn(['subtitle', 'category_label', 'intro_text', 'author_name', 'reviewer_name']);
        });
    }
};
