<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('auth.primary_admin.email');
        $name = trim((string) config('auth.primary_admin.name', 'Remedic'));
        $lastName = trim((string) config('auth.primary_admin.last_name', 'Admin'));
        $initialPassword = (string) config('auth.primary_admin.initial_password');

        $existingUser = User::query()->where('email', $email)->first();

        if (! $existingUser && $initialPassword === '') {
            throw new RuntimeException('PRIMARY_ADMIN_PASSWORD non configurata. Impostala nell\'ambiente prima di eseguire il seeding iniziale.');
        }

        if ($existingUser) {
            $existingUser->forceFill([
                'name' => $existingUser->name ?: $name,
                'last_name' => $existingUser->last_name ?: $lastName,
                'role' => UserRole::Admin,
                'is_active' => true,
                'email_verified_at' => $existingUser->email_verified_at ?? now(),
                'approval_requested_at' => $existingUser->approval_requested_at ?? $existingUser->created_at ?? now(),
                'admin_approved_at' => $existingUser->admin_approved_at ?? $existingUser->email_verified_at ?? now(),
                'rejected_at' => null,
                'rejected_by_user_id' => null,
            ])->save();

            return;
        }

        User::query()->create([
            'name' => $name,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make($initialPassword),
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
            'approval_requested_at' => now(),
            'admin_approved_at' => now(),
            'approved_by_user_id' => null,
            'rejected_at' => null,
            'rejected_by_user_id' => null,
        ]);
    }
}
