<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('email')->unique();
            $table->string('status')->default('pending')->index();
            $table->string('consent_version')->nullable();
            $table->timestamp('consent_requested_at')->nullable();
            $table->string('confirmation_token_hash', 64)->nullable()->index();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
