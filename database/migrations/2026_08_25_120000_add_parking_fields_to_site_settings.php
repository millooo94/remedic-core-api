<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->string('parking_label')->nullable()->after('served_territory');
            $table->string('parking_address')->nullable()->after('parking_label');
            $table->text('parking_description')->nullable()->after('parking_address');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->dropColumn(['parking_label', 'parking_address', 'parking_description']);
        });
    }
};
