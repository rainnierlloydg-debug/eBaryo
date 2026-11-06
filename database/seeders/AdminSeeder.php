<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');
        if (! $email || ! $password) {
            $this->command?->warn('Admin was not created. Set ADMIN_EMAIL and ADMIN_PASSWORD in .env, then run db:seed again.');
            return;
        }

        $existing = User::where('email', $email)->first();
        if ($existing && $existing->role !== 'admin') {
            throw new RuntimeException('The configured admin email already belongs to a resident account.');
        }

        if (User::where('role', 'admin')->where('email', '!=', $email)->exists()) {
            throw new RuntimeException('Only one Admin account is allowed.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Barangay Admin'),
                'password' => Hash::make($password),
                'role' => 'admin',
                'account_status' => 'active',
                'verification_status' => 'verified',
            ],
        );
    }
}
