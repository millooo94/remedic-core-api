<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->string('parking_street_name')->nullable()->after('parking_address');
            $table->string('parking_street_number', 32)->nullable()->after('parking_street_name');
            $table->string('parking_postal_code', 20)->nullable()->after('parking_street_number');
            $table->string('parking_city')->nullable()->after('parking_postal_code');
            $table->string('parking_province', 100)->nullable()->after('parking_city');
            $table->string('parking_region', 100)->nullable()->after('parking_province');
            $table->string('parking_country_name', 100)->nullable()->after('parking_region');
            $table->string('parking_country', 2)->nullable()->after('parking_country_name');
            $table->string('parking_google_place_id')->nullable()->after('parking_country');
            $table->decimal('parking_latitude', 10, 7)->nullable()->after('parking_google_place_id');
            $table->decimal('parking_longitude', 10, 7)->nullable()->after('parking_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'parking_street_name', 'parking_street_number', 'parking_postal_code',
                'parking_city', 'parking_province', 'parking_region', 'parking_country_name',
                'parking_country', 'parking_google_place_id', 'parking_latitude', 'parking_longitude',
            ]);
        });
    }
};
