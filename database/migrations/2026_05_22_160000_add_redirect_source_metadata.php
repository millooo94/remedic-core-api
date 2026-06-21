<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirects', function (Blueprint $table): void {
            if (! Schema::hasColumn('redirects', 'is_automatic')) {
                $table->boolean('is_automatic')->default(false)->after('is_active');
                $table->index('is_automatic');
            }

            if (! Schema::hasColumn('redirects', 'source_type')) {
                $table->string('source_type', 80)->nullable()->after('is_automatic');
                $table->index('source_type');
            }

            if (! Schema::hasColumn('redirects', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
                $table->index(['source_type', 'source_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('redirects', function (Blueprint $table): void {
            if (Schema::hasColumn('redirects', 'source_id')) {
                $table->dropIndex(['source_type', 'source_id']);
                $table->dropColumn('source_id');
            }

            if (Schema::hasColumn('redirects', 'source_type')) {
                $table->dropIndex(['source_type']);
                $table->dropColumn('source_type');
            }

            if (Schema::hasColumn('redirects', 'is_automatic')) {
                $table->dropIndex(['is_automatic']);
                $table->dropColumn('is_automatic');
            }
        });
    }
};
