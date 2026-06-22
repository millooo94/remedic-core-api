<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('old_core_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 80)->index();
            $table->string('old_table', 120)->index();
            $table->unsignedBigInteger('old_id');
            $table->string('new_table', 120)->index();
            $table->unsignedBigInteger('new_id');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'old_table', 'old_id'], 'old_core_import_unique');
            $table->index(['new_table', 'new_id'], 'old_core_import_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('old_core_import_mappings');
    }
};
