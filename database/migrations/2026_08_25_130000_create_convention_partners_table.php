<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convention_partners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 32);
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convention_partners');
    }
};
