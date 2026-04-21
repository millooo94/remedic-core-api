<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professionals', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name')->index();
            $table->string('area_name')->index();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('canonical_name');
            $table->string('display_name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('default_duration_minutes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('service_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('alias_name');
            $table->string('alias_slug')->index();
            $table->string('source_label')->nullable();
            $table->timestamps();
        });

        Schema::create('professional_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('price_amount', 10, 2)->nullable();
            $table->boolean('is_visible_public')->default(true);
            $table->boolean('is_bookable_online')->default(false);
            $table->string('source_platform')->nullable();
            $table->text('source_notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['professional_id', 'service_id']);
        });

        Schema::create('counting_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->text('notes')->nullable();
            $table->boolean('is_closed')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('performance_records', function (Blueprint $table): void {
            $table->id();
            $table->date('performed_at')->index();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('professional_name_snapshot')->index();
            $table->string('category_name_snapshot')->nullable()->index();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('service_name_snapshot')->index();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_amount', 10, 2);
            $table->decimal('total_amount', 12, 2)->index();
            $table->enum('calculation_mode', ['percentage', 'fixed'])->index();
            $table->decimal('percentage_value', 5, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->decimal('professional_amount', 12, 2);
            $table->decimal('center_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->enum('type', ['fixed', 'variable'])->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->string('name');
            $table->enum('type', ['fixed', 'variable'])->index();
            $table->enum('recurrence', ['monthly', 'bimonthly', 'quarterly', 'yearly', 'manual'])->default('monthly')->index();
            $table->decimal('default_amount', 12, 2);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('expense_template_id')->nullable()->constrained('expense_templates')->nullOnDelete();
            $table->date('expense_date')->index();
            $table->unsignedTinyInteger('competence_month')->index();
            $table->unsignedSmallInteger('competence_year')->index();
            $table->string('description');
            $table->enum('type', ['fixed', 'variable'])->index();
            $table->decimal('amount', 12, 2);
            $table->string('supplier')->nullable();
            $table->enum('payment_status', ['da_pagare', 'pagata'])->default('pagata')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type')->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('action')->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('application_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('reminder_email')->nullable();
            $table->json('reminder_dates')->nullable();
            $table->json('quick_percentages')->nullable();
            $table->json('quarter_shortcuts')->nullable();
            $table->json('general_preferences')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('expense_records');
        Schema::dropIfExists('expense_templates');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('performance_records');
        Schema::dropIfExists('counting_periods');
        Schema::dropIfExists('professional_services');
        Schema::dropIfExists('service_aliases');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('professionals');
    }
};
