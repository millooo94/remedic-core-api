<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_popups', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('eyebrow')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('primary_cta_label')->nullable();
            $table->string('primary_cta_target')->nullable();
            $table->string('secondary_cta_label')->nullable();
            $table->string('secondary_cta_target')->nullable();
            $table->unsignedInteger('campaign_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_popups');
    }
};
