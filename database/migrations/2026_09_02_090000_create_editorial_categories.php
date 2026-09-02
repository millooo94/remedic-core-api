<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type', 32);
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['content_type', 'slug']);
        });
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->foreignId('editorial_category_id')->nullable()->after('editorial_category')->constrained('editorial_categories')->nullOnDelete();
        });

        $seed = ['health_pill' => ['nutrition' => 'Nutrizione', 'cardiology' => 'Cardiologia', 'wellness' => 'Benessere', 'prevention' => 'Prevenzione', 'respiration' => 'Respirazione'], 'news' => ['services' => 'Servizi', 'professionals' => 'Professionisti', 'initiatives' => 'Iniziative', 'technology' => 'Tecnologia', 'network' => 'Network', 'center' => 'Centro']];
        foreach ($seed as $type => $categories) {
            foreach ($categories as $slug => $name) {
                DB::table('editorial_categories')->updateOrInsert(['content_type' => $type, 'slug' => $slug], ['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('blog_posts')->orderBy('id')->eachById(function (object $post): void {
            if (! $post->editorial_category) {
                return;
            }
            $id = DB::table('editorial_categories')->where('content_type', $post->content_type)->where('slug', $post->editorial_category)->value('id');
            if ($id) {
                DB::table('blog_posts')->where('id', $post->id)->update(['editorial_category_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', fn (Blueprint $table) => $table->dropConstrainedForeignId('editorial_category_id'));
        Schema::dropIfExists('editorial_categories');
    }
};
