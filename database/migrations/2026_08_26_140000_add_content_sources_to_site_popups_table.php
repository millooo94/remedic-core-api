<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_popups', function (Blueprint $table): void {
            $table->string('source_type', 20)->default('manual')->after('is_active');
            $table->foreignId('promotion_id')->nullable()->after('source_type')->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->after('promotion_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_popups', function (Blueprint $table): void {
            $table->dropForeign(['promotion_id']);
            $table->dropForeign(['event_id']);
            $table->dropColumn(['source_type', 'promotion_id', 'event_id']);
        });
    }
};
