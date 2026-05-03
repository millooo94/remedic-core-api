<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('patients', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('full_name')->index();
            }
        });

        DB::table('patients')
            ->whereNull('birth_date')
            ->whereNotNull('year_of_birth')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('patients')
                        ->where('id', $row->id)
                        ->update([
                            'birth_date' => sprintf('%04d-01-01', (int) $row->year_of_birth),
                        ]);
                }
            });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE marketing_campaigns
                MODIFY channel ENUM('sms','whatsapp','email','all') NOT NULL
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE marketing_campaigns
                MODIFY channel ENUM('sms','whatsapp','email') NOT NULL
            ");
        }

        Schema::table('patients', function (Blueprint $table): void {
            if (Schema::hasColumn('patients', 'birth_date')) {
                $table->dropColumn('birth_date');
            }
        });
    }
};
