<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_translations')) {
            Schema::create('content_translations', function (Blueprint $table): void {
                $table->id();
                $table->string('translatable_type', 120);
                $table->unsignedBigInteger('translatable_id');
                $table->string('locale', 2);
                $table->string('title')->nullable();
                $table->string('slug')->nullable();
                $table->text('excerpt')->nullable();
                $table->longText('intro_text')->nullable();
                $table->text('short_description')->nullable();
                $table->string('subtitle')->nullable();
                $table->string('category_label')->nullable();
                $table->longText('body')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->string('seo_h1')->nullable();
                $table->string('og_title')->nullable();
                $table->text('og_description')->nullable();
                $table->string('publication_state', 16)->default('draft');
                $table->string('source_revision', 64)->nullable();
                $table->string('reviewed_source_revision', 64)->nullable();
                $table->timestamps();
                $table->unique(['translatable_type', 'translatable_id', 'locale'], 'content_translation_owner_locale_unique');
                $table->unique(['translatable_type', 'locale', 'slug'], 'content_translation_slug_locale_unique');
                $table->index(['locale', 'publication_state']);
            });
        }
        if (! Schema::hasTable('section_translations')) {
            Schema::create('section_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('section_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 2);
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->longText('content')->nullable();
                $table->timestamps();
                $table->unique(['section_id', 'locale']);
            });
        }
        if (! Schema::hasTable('faq_item_translations')) {
            Schema::create('faq_item_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('faq_item_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 2);
                $table->text('question')->nullable();
                $table->longText('answer')->nullable();
                $table->timestamps();
                $table->unique(['faq_item_id', 'locale']);
            });
        }
        if (! Schema::hasTable('site_index_page_translations')) {
            Schema::create('site_index_page_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_index_page_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 2);
                $table->string('title')->nullable();
                $table->string('slug')->nullable();
                $table->json('content')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->string('seo_h1')->nullable();
                $table->string('publication_state', 16)->default('draft');
                $table->string('source_revision', 64)->nullable();
                $table->string('reviewed_source_revision', 64)->nullable();
                $table->timestamps();
                $table->unique(['site_index_page_id', 'locale']);
                $table->unique(['locale', 'slug']);
            });
        }
        if (! Schema::hasTable('site_navigation_translations')) {
            Schema::create('site_navigation_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_navigation_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 2);
                $table->json('configuration')->nullable();
                $table->string('publication_state', 16)->default('draft');
                $table->string('source_revision', 64)->nullable();
                $table->string('reviewed_source_revision', 64)->nullable();
                $table->timestamps();
                $table->unique(['site_navigation_id', 'locale']);
            });
        }
        if (! Schema::hasTable('site_popup_translations')) {
            Schema::create('site_popup_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_popup_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 2);
                $table->string('eyebrow')->nullable();
                $table->string('title')->nullable();
                $table->longText('body')->nullable();
                $table->string('primary_cta_label')->nullable();
                $table->string('secondary_cta_label')->nullable();
                $table->string('publication_state', 16)->default('draft');
                $table->string('source_revision', 64)->nullable();
                $table->string('reviewed_source_revision', 64)->nullable();
                $table->timestamps();
                $table->unique(['site_popup_id', 'locale']);
            });
        }
        if (! Schema::hasTable('global_seo_translations')) {
            Schema::create('global_seo_translations', function (Blueprint $table): void {
                $table->id();
                $table->string('locale', 2)->unique();
                $table->string('default_meta_title')->nullable();
                $table->text('default_meta_description')->nullable();
                $table->string('default_social_image_path')->nullable();
                $table->string('publication_state', 16)->default('draft');
                $table->timestamps();
            });
        }

        $this->backfillItalian();
    }

    private function backfillItalian(): void
    {
        $now = now();
        $contentTypes = [
            'pages' => ['App\\Models\\Page', ['title', 'slug', 'excerpt', 'intro_text', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']],
            'specialization_web_profiles' => ['App\\Models\\SpecializationWebProfile', ['slug', 'short_description', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']],
            'service_web_profiles' => ['App\\Models\\ServiceWebProfile', ['public_slug', 'short_description', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']],
            'professional_public_profiles' => ['App\\Models\\ProfessionalPublicProfile', ['slug', 'short_bio', 'bio_content', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']],
            'checkup_web_profiles' => ['App\\Models\\CheckupWebProfile', ['public_slug', 'short_description', 'category_label', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']],
            'blog_posts' => ['App\\Models\\BlogPost', ['title', 'slug', 'subtitle', 'category_label', 'excerpt', 'intro_text', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']],
        ];
        foreach ($contentTypes as $table => [$type, $fields]) {
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $data = ['translatable_type' => $type, 'translatable_id' => $row->id, 'locale' => 'it', 'publication_state' => 'published', 'source_revision' => hash('sha256', json_encode((array) $row)), 'reviewed_source_revision' => hash('sha256', json_encode((array) $row)), 'created_at' => $now, 'updated_at' => $now];
                $data['title'] = $this->masterTitle($table, $row) ?? $row->title ?? $row->short_description ?? $row->category_label ?? $row->slug ?? $row->public_slug ?? null;
                $data['slug'] = $row->slug ?? $row->public_slug ?? null;
                foreach ($fields as $field) {
                    $data[match ($field) {
                        'public_slug' => 'slug', 'short_bio' => 'short_description', 'bio_content' => 'body', default => $field
                    }] = $row->{$field} ?? ($data[match ($field) {
                        'public_slug' => 'slug', 'short_bio' => 'short_description', 'bio_content' => 'body', default => $field
                    }] ?? null);
                }
                DB::table('content_translations')->insertOrIgnore($data);
            }
        }
        foreach (DB::table('sections')->orderBy('id')->get() as $row) {
            DB::table('section_translations')->insertOrIgnore(['section_id' => $row->id, 'locale' => 'it', 'title' => $row->title, 'subtitle' => $row->subtitle, 'content' => $row->content, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('faq_items')->orderBy('id')->get() as $row) {
            DB::table('faq_item_translations')->insertOrIgnore(['faq_item_id' => $row->id, 'locale' => 'it', 'question' => $row->question, 'answer' => $row->answer, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('site_index_pages')->orderBy('id')->get() as $row) {
            DB::table('site_index_page_translations')->insertOrIgnore(['site_index_page_id' => $row->id, 'locale' => 'it', 'title' => $row->title, 'slug' => $row->slug, 'content' => $row->content, 'seo_title' => $row->seo_title, 'seo_description' => $row->seo_description, 'seo_h1' => $row->seo_h1, 'publication_state' => $row->is_active && $row->published_at && strtotime((string) $row->published_at) <= $now->getTimestamp() ? 'published' : 'draft', 'source_revision' => hash('sha256', (string) $row->content), 'reviewed_source_revision' => hash('sha256', (string) $row->content), 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('site_navigations')->orderBy('id')->get() as $row) {
            DB::table('site_navigation_translations')->insertOrIgnore(['site_navigation_id' => $row->id, 'locale' => 'it', 'configuration' => $row->configuration, 'publication_state' => 'published', 'source_revision' => hash('sha256', (string) $row->configuration), 'reviewed_source_revision' => hash('sha256', (string) $row->configuration), 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('site_popups')->orderBy('id')->get() as $row) {
            DB::table('site_popup_translations')->insertOrIgnore(['site_popup_id' => $row->id, 'locale' => 'it', 'eyebrow' => $row->eyebrow, 'title' => $row->title, 'body' => $row->body, 'primary_cta_label' => $row->primary_cta_label, 'secondary_cta_label' => $row->secondary_cta_label, 'publication_state' => 'published', 'source_revision' => hash('sha256', implode('|', [$row->eyebrow, $row->title, $row->body])), 'reviewed_source_revision' => hash('sha256', implode('|', [$row->eyebrow, $row->title, $row->body])), 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('site_settings')->orderBy('id')->get() as $row) {
            DB::table('global_seo_translations')->insertOrIgnore(['locale' => 'it', 'default_meta_title' => $row->default_meta_title, 'default_meta_description' => $row->default_meta_description, 'default_social_image_path' => $row->default_og_image_path, 'publication_state' => 'published', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function masterTitle(string $table, object $row): ?string
    {
        return match ($table) {
            'specialization_web_profiles' => DB::table('specializations')->where('id', $row->specialization_id)->value('name'),
            'service_web_profiles' => DB::table('services')->where('id', $row->service_id)->value('display_name'),
            'professional_public_profiles' => DB::table('professionals')->where('id', $row->professional_id)->value('full_name'),
            'checkup_web_profiles' => DB::table('checkups')->where('id', $row->checkup_id)->value('display_name'),
            default => null,
        };
    }

    public function down(): void
    {
        Schema::dropIfExists('global_seo_translations');
        Schema::dropIfExists('site_popup_translations');
        Schema::dropIfExists('site_navigation_translations');
        Schema::dropIfExists('site_index_page_translations');
        Schema::dropIfExists('faq_item_translations');
        Schema::dropIfExists('section_translations');
        Schema::dropIfExists('content_translations');
    }
};
