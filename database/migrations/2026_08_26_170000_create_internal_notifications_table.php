<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 100);
            $table->string('context', 100);
            $table->string('title', 180);
            $table->string('message', 500);
            $table->string('severity', 20)->default('info');
            $table->json('action')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_public_id', 100)->nullable();
            $table->string('deduplication_key', 191)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'read_at'], 'internal_notifications_recipient_read_idx');
            $table->index(['recipient_user_id', 'context', 'read_at'], 'internal_notifications_recipient_context_read_idx');
            $table->unique(['recipient_user_id', 'deduplication_key'], 'internal_notifications_recipient_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_notifications');
    }
};
