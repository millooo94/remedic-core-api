<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('checkup_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('promotional_price', 10, 2);
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('validity_basis', 32)->default('booking_date');
            $table->boolean('is_active')->default(false);
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['service_id', 'is_active', 'start_at', 'end_at']);
            $table->index(['checkup_id', 'is_active', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
