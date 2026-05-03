<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_performance_record', function (Blueprint $table): void {
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('performance_record_id')->constrained('performance_records')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['performance_record_id', 'patient_id'], 'patient_performance_record_unique');
            $table->index(['performance_record_id', 'sort_order'], 'patient_performance_record_sort_idx');
            $table->index('patient_id', 'patient_performance_record_patient_idx');
        });

        DB::table('performance_records')
            ->select(['id', 'patient_id'])
            ->whereNotNull('patient_id')
            ->orderBy('id')
            ->chunkById(200, function ($records): void {
                $rows = [];

                foreach ($records as $record) {
                    $rows[] = [
                        'patient_id' => (int) $record->patient_id,
                        'performance_record_id' => (int) $record->id,
                        'sort_order' => 0,
                    ];
                }

                if ($rows !== []) {
                    DB::table('patient_performance_record')->upsert(
                        $rows,
                        ['performance_record_id', 'patient_id'],
                        ['sort_order'],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_performance_record');
    }
};
