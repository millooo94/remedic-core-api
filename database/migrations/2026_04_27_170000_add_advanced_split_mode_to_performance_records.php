<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('performance_records', 'split_mode')) {
                $table->enum('split_mode', ['standard', 'advanced'])
                    ->default('standard')
                    ->index()
                    ->after('calculation_mode');
            }
        });

        Schema::create('performance_record_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('performance_record_id')->constrained('performance_records')->cascadeOnDelete();
            $table->enum('subject_type', ['professional', 'center'])->index();
            $table->foreignId('professional_id')->nullable()->constrained('professionals')->nullOnDelete();
            $table->string('professional_name_snapshot')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['performance_record_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_record_splits');

        Schema::table('performance_records', function (Blueprint $table): void {
            if (Schema::hasColumn('performance_records', 'split_mode')) {
                $table->dropColumn('split_mode');
            }
        });
    }
};
