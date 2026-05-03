<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_campaigns', 'whatsapp_image_path')) {
                $table->string('whatsapp_image_path', 255)->nullable()->after('message');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'whatsapp_image_original_name')) {
                $table->string('whatsapp_image_original_name', 190)->nullable()->after('whatsapp_image_path');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'whatsapp_image_mime_type')) {
                $table->string('whatsapp_image_mime_type', 80)->nullable()->after('whatsapp_image_original_name');
            }

            if (! Schema::hasColumn('marketing_campaigns', 'whatsapp_image_size')) {
                $table->unsignedBigInteger('whatsapp_image_size')->nullable()->after('whatsapp_image_mime_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table): void {
            foreach ([
                'whatsapp_image_path',
                'whatsapp_image_original_name',
                'whatsapp_image_mime_type',
                'whatsapp_image_size',
            ] as $column) {
                if (Schema::hasColumn('marketing_campaigns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
