<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_web_profiles', function (Blueprint $table): void {
            $table->dropIndex(['list_sort_order']);
            $table->dropColumn('list_sort_order');
        });

        Schema::table('specialization_web_profiles', function (Blueprint $table): void {
            $table->dropIndex(['list_sort_order']);
            $table->dropColumn('list_sort_order');
        });

        Schema::table('checkup_web_profiles', function (Blueprint $table): void {
            $table->dropIndex(['list_sort_order']);
            $table->dropColumn('list_sort_order');
        });

        Schema::table('professional_public_profiles', function (Blueprint $table): void {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('professional_public_profiles', function (Blueprint $table): void {
            $table->integer('sort_order')->default(0)->index();
        });

        Schema::table('service_web_profiles', function (Blueprint $table): void {
            $table->integer('list_sort_order')->default(0)->index();
        });

        Schema::table('checkup_web_profiles', function (Blueprint $table): void {
            $table->integer('list_sort_order')->default(0)->index();
        });

        Schema::table('specialization_web_profiles', function (Blueprint $table): void {
            $table->integer('list_sort_order')->default(0)->index();
        });
    }
};
