<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_id', 191)->nullable();
            $table->string('author_name', 191);
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('source_metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();
            $table->unique(['page_id', 'provider', 'external_id'], 'page_reviews_provider_external_unique');
            $table->index(['page_id', 'provider', 'is_available'], 'page_reviews_page_provider_available_idx');
        });

        Schema::create('page_featured_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->foreignId('page_review_id')->nullable()->constrained('page_reviews')->nullOnDelete();
            $table->timestamps();
            $table->unique(['page_id', 'provider'], 'page_featured_reviews_page_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_featured_reviews');
        Schema::dropIfExists('page_reviews');
    }
};
