<?php

use App\Models\BlogPost;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sections', 'template')) {
            Schema::table('sections', function (Blueprint $table): void {
                $table->string('template', 32)->nullable()->after('key');
                $table->index('template');
            });
        }

        DB::table('sections')->where('sectionable_type', BlogPost::class)->whereNull('template')->update(['template' => 'text']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sections', 'template')) {
            Schema::table('sections', function (Blueprint $table): void {
                $table->dropIndex(['template']);
                $table->dropColumn('template');
            });
        }
    }
};
