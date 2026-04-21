<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('recipient_email');
            $table->string('subject');
            $table->text('body');
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'yearly'])->default('monthly')->index();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        DB::table('reminders')->insert([
            'title' => 'Promemoria conteggi mensili',
            'recipient_email' => 'humancaretelemedicine@gmail.com',
            'subject' => 'Promemoria prospetti professionisti Remedic',
            'body' => 'Oggi e il giorno previsto per verificare le prestazioni effettuate e preparare i prospetti da inviare ai professionisti.',
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'day_of_week' => null,
            'is_active' => true,
            'notes' => 'Promemoria operativo standard.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};

