<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_categories', function (Blueprint $table): void {
            $table->dropUnique(['content_type', 'slug']);
            $table->dropColumn('slug');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_categories', function (Blueprint $table): void {
            $table->string('slug')->nullable();
            $table->unique(['content_type', 'slug']);
        });
    }
};
