<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('approval_requested_at')->nullable()->after('email_verified_at');
            $table->timestamp('admin_approved_at')->nullable()->after('approval_requested_at');
            $table->foreignId('approved_by_user_id')->nullable()->after('admin_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_by_user_id');
            $table->foreignId('rejected_by_user_id')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
        });

        DB::table('users')
            ->select(['id', 'created_at', 'email_verified_at'])
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $approvedAt = $user->email_verified_at ?? $user->created_at ?? now();
                    $requestedAt = $user->created_at ?? $approvedAt ?? now();

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'approval_requested_at' => $requestedAt,
                            'admin_approved_at' => $approvedAt,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rejected_by_user_id');
            $table->dropColumn('rejected_at');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn([
                'admin_approved_at',
                'approval_requested_at',
            ]);
        });
    }
};
