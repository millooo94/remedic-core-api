<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('performance_records', 'payment_status')) {
            Schema::table('performance_records', function (Blueprint $table): void {
                $table->enum('payment_status', ['da_pagare', 'pagata'])
                    ->default('da_pagare')
                    ->index()
                    ->after('payment_method');
            });
        }

        DB::table('performance_records')->update([
            'payment_status' => 'da_pagare',
        ]);

        $resolvedStatuses = DB::table('expense_records')
            ->select('source_performance_record_id')
            ->selectRaw("
                CASE
                    WHEN SUM(CASE WHEN payment_status = 'da_pagare' THEN 1 ELSE 0 END) > 0
                        THEN 'da_pagare'
                    ELSE 'pagata'
                END AS resolved_payment_status
            ")
            ->whereNotNull('source_performance_record_id')
            ->groupBy('source_performance_record_id')
            ->get();

        foreach ($resolvedStatuses as $row) {
            DB::table('performance_records')
                ->where('id', $row->source_performance_record_id)
                ->update([
                    'payment_status' => $row->resolved_payment_status,
                ]);
        }

        DB::table('expense_records')
            ->whereNotNull('source_performance_record_id')
            ->orderBy('id')
            ->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    $paymentStatus = DB::table('performance_records')
                        ->where('id', $record->source_performance_record_id)
                        ->value('payment_status');

                    if ($paymentStatus === null) {
                        continue;
                    }

                    DB::table('expense_records')
                        ->where('id', $record->id)
                        ->update([
                            'payment_status' => $paymentStatus,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('performance_records', 'payment_status')) {
            return;
        }

        Schema::table('performance_records', function (Blueprint $table): void {
            $table->dropIndex('performance_records_payment_status_index');
            $table->dropColumn('payment_status');
        });
    }
};
