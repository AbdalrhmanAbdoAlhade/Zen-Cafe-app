<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@zenchef.com'],
            [
                'name'              => 'Zen Super Admin',
                'email_verified_at' => now(),
                'password'          => Hash::make('ChangeMe@2026!'),
                'role'              => 'super_admin',
            ]
        );

        $this->command->info('✅ Super Admin: admin@zenchef.com / ChangeMe@2026!');
    }
}