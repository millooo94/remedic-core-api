<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('expense_records', 'competence_start_date')) {
                $table->date('competence_start_date')->nullable()->after('expense_date')->index();
            }

            if (! Schema::hasColumn('expense_records', 'competence_end_date')) {
                $table->date('competence_end_date')->nullable()->after('competence_start_date')->index();
            }

            if (! Schema::hasColumn('expense_records', 'competence_months_count')) {
                $table->unsignedSmallInteger('competence_months_count')->default(1)->after('competence_end_date');
            }
        });

        DB::table('expense_records')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $year = (int) ($row->competence_year ?? 0);
                    $month = (int) ($row->competence_month ?? 0);

                    if ($year < 1900 || $month < 1 || $month > 12) {
                        continue;
                    }

                    $start = sprintf('%04d-%02d-01', $year, $month);

                    DB::table('expense_records')
                        ->where('id', $row->id)
                        ->update([
                            'competence_start_date' => $start,
                            'competence_end_date' => $start,
                            'competence_months_count' => 1,
                        ]);
                }
            });

        Schema::create('expense_record_competences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_record_id')->constrained('expense_records')->cascadeOnDelete();
            $table->date('competence_date')->index();
            $table->unsignedTinyInteger('competence_month')->index();
            $table->unsignedSmallInteger('competence_year')->index();
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamps();

            $table->unique(['expense_record_id', 'competence_year', 'competence_month'], 'expense_record_competences_unique_month');
        });

        DB::table('expense_records')
            ->select(['id', 'amount', 'competence_start_date', 'competence_month', 'competence_year'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $timestamp = now();
                $inserts = [];

                foreach ($rows as $row) {
                    $competenceDate = $row->competence_start_date;

                    if (! $competenceDate && $row->competence_year && $row->competence_month) {
                        $competenceDate = sprintf('%04d-%02d-01', (int) $row->competence_year, (int) $row->competence_month);
                    }

                    if (! $competenceDate) {
                        continue;
                    }

                    $inserts[] = [
                        'expense_record_id' => $row->id,
                        'competence_date' => $competenceDate,
                        'competence_month' => (int) date('n', strtotime($competenceDate)),
                        'competence_year' => (int) date('Y', strtotime($competenceDate)),
                        'allocated_amount' => $row->amount,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($inserts !== []) {
                    DB::table('expense_record_competences')->insert($inserts);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_record_competences');

        Schema::table('expense_records', function (Blueprint $table): void {
            if (Schema::hasColumn('expense_records', 'competence_months_count')) {
                $table->dropColumn('competence_months_count');
            }

            if (Schema::hasColumn('expense_records', 'competence_end_date')) {
                $table->dropColumn('competence_end_date');
            }

            if (Schema::hasColumn('expense_records', 'competence_start_date')) {
                $table->dropColumn('competence_start_date');
            }
        });
    }
};
