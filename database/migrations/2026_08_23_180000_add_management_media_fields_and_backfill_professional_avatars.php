<?php

use App\Services\ProfessionalAvatarBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specializations', function (Blueprint $table): void {
            $table->string('featured_image_path')->nullable()->after('icon_path');
        });

        Schema::table('checkups', function (Blueprint $table): void {
            $table->string('featured_image_path')->nullable()->after('organizational_notes');
            $table->string('icon_path')->nullable()->after('featured_image_path');
        });

        app(ProfessionalAvatarBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('checkups', function (Blueprint $table): void {
            $table->dropColumn(['featured_image_path', 'icon_path']);
        });

        Schema::table('specializations', function (Blueprint $table): void {
            $table->dropColumn('featured_image_path');
        });
    }
};
