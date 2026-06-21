<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reminders')
            ->where('frequency', 'quarterly')
            ->delete();
    }

    public function down(): void
    {
        // Rimozione dati intenzionale e non ricostruibile automaticamente.
    }
};
