<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->date('movement_date')->index();
            $table->enum('cash_box_type', ['fatturati', 'black'])->index();
            $table->enum('movement_type', ['versamento', 'prelievo'])->index();
            $table->string('counterparty_name');
            $table->decimal('amount', 12, 2);
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cash_box_type', 'movement_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
