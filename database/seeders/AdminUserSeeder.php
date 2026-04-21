<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'camillomusmeci.dev@gmail.com';

        User::query()
            ->where('email', '!=', $email)
            ->delete();

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Camillo',
                'last_name' => 'Musmeci',
                'password' => Hash::make('Spicoccio949494!'),
                'role' => UserRole::Admin,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
