<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->string('editorial_category')->nullable()->index()->after('content_type');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropIndex(['editorial_category']);
            $table->dropColumn('editorial_category');
        });
    }
};
