<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkups', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name', 190)->unique();
            $table->decimal('price_amount', 10, 2);
            $table->unsignedSmallInteger('indicative_duration_minutes');
            $table->boolean('is_active')->default(true)->index();
            $table->text('organizational_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('checkup_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('checkup_id')->constrained('checkups')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['checkup_id', 'service_id'], 'checkup_services_checkup_service_unique');
            $table->unique(['checkup_id', 'sort_order'], 'checkup_services_checkup_sort_unique');
            $table->index('service_id', 'checkup_services_service_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkup_services');
        Schema::dropIfExists('checkups');
    }
};
