<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            if (! Schema::hasColumn('patients', 'tax_code')) {
                $table->string('tax_code', 16)->nullable()->after('full_name')->index();
            }
            if (! Schema::hasColumn('patients', 'residence_address')) {
                $table->string('residence_address')->nullable()->after('email');
            }
            if (! Schema::hasColumn('patients', 'residence_city')) {
                $table->string('residence_city', 120)->nullable()->after('residence_address')->index();
            }
            if (! Schema::hasColumn('patients', 'residence_zip')) {
                $table->string('residence_zip', 10)->nullable()->after('residence_city')->index();
            }
            if (! Schema::hasColumn('patients', 'residence_latitude')) {
                $table->decimal('residence_latitude', 10, 7)->nullable()->after('residence_zip')->index();
            }
            if (! Schema::hasColumn('patients', 'residence_longitude')) {
                $table->decimal('residence_longitude', 10, 7)->nullable()->after('residence_latitude')->index();
            }
            if (! Schema::hasColumn('patients', 'geocoding_status')) {
                $table->string('geocoding_status', 20)->nullable()->after('residence_longitude')->index();
            }
            if (! Schema::hasColumn('patients', 'geocoded_at')) {
                $table->timestamp('geocoded_at')->nullable()->after('geocoding_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            foreach ([
                'tax_code',
                'residence_address',
                'residence_city',
                'residence_zip',
                'residence_latitude',
                'residence_longitude',
                'geocoding_status',
                'geocoded_at',
            ] as $column) {
                if (Schema::hasColumn('patients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

