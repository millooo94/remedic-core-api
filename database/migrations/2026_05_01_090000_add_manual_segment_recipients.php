<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_segments', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_segments', 'segment_type')) {
                $table->string('segment_type', 32)->default('filter_based')->after('description')->index();
            }
        });

        Schema::create('marketing_segment_manual_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_segment_id')->constrained('marketing_segments')->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('original_value', 80);
            $table->string('normalized_phone', 40)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['marketing_segment_id', 'normalized_phone'], 'segment_manual_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_segment_manual_recipients');

        Schema::table('marketing_segments', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_segments', 'segment_type')) {
                $table->dropColumn('segment_type');
            }
        });
    }
};
