<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expense_records', 'nature')) {
            Schema::table('expense_records', function (Blueprint $table): void {
                $table->string('nature', 16)->default('ordinary')->after('type')->index();
            });
        }

        DB::table('expense_records')
            ->orderBy('id')
            ->each(function (object $expense): void {
                $nature = $expense->nature ?? 'ordinary';

                if ($expense->source_performance_record_id !== null) {
                    $performance = DB::table('performance_records')
                        ->select(['is_black', 'is_provvigione'])
                        ->where('id', $expense->source_performance_record_id)
                        ->first();

                    if ($performance !== null && ((bool) $performance->is_black || (bool) $performance->is_provvigione)) {
                        $nature = 'special';
                    }
                }

                if ($expense->nature !== $nature) {
                    DB::table('expense_records')->where('id', $expense->id)->update(['nature' => $nature]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('expense_records', 'nature')) {
            Schema::table('expense_records', function (Blueprint $table): void {
                $table->dropIndex(['nature']);
                $table->dropColumn('nature');
            });
        }
    }
};
