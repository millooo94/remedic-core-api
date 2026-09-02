<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_configuration_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('configuration_version')->unique();
            $table->json('snapshot');
            $table->timestamp('published_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_configuration_versions');
    }
};
