<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('internal_name');
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->text('content');
            $table->string('status')->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sending_started_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->timestamp('last_test_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('launched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('newsletter_campaign_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('newsletter_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('newsletter_subscriber_id')->constrained()->restrictOnDelete();
            $table->string('email_snapshot');
            $table->string('delivery_status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('suppressed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['newsletter_campaign_id', 'newsletter_subscriber_id'], 'newsletter_campaign_delivery_subscriber_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_campaign_deliveries');
        Schema::dropIfExists('newsletter_campaigns');
    }
};
