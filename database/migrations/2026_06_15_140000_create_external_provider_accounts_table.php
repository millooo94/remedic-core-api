<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 80)->unique();
            $table->string('label', 120);
            $table->boolean('enabled')->default(false);
            $table->text('username_encrypted')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('storage_state_path')->nullable();
            $table->string('login_status', 40)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_availability_sync_at')->nullable();
            $table->timestamp('last_patient_sync_at')->nullable();
            $table->timestamp('last_appointment_sync_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_provider_accounts');
    }
};
