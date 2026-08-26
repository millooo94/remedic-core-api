<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('locale', 2);
            $table->string('result_type', 32);
            $table->string('href', 500);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('image_path')->nullable();
            $table->string('normalized_title');
            $table->longText('normalized_text');
            $table->text('searchable_tokens');
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'locale'], 'search_documents_source_locale_unique');
            $table->index(['locale', 'result_type', 'id']);
            $table->index(['locale', 'normalized_title']);
        });

        Schema::create('search_document_ngrams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_document_id')->constrained()->cascadeOnDelete();
            $table->string('gram', 3);
            $table->timestamps();
            $table->unique(['search_document_id', 'gram']);
            $table->index(['gram', 'search_document_id']);
        });

        Schema::create('search_synonym_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 2);
            $table->string('canonical_term', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['locale', 'canonical_term']);
        });

        Schema::create('search_synonyms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_synonym_group_id')->constrained()->cascadeOnDelete();
            $table->string('term', 150);
            $table->timestamps();
            $table->unique(['search_synonym_group_id', 'term']);
        });

        // MariaDB uses this derived index for longer content queries. SQLite tests
        // intentionally use the portable LIKE + n-gram candidate path instead.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE search_documents ADD FULLTEXT search_documents_fulltext (normalized_title, normalized_text)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
        Schema::dropIfExists('search_synonym_groups');
        Schema::dropIfExists('search_document_ngrams');
        Schema::dropIfExists('search_documents');
    }
};
