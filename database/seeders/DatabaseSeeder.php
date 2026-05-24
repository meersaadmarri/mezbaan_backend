<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * Safe to run multiple times (idempotent).
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'role' => 'customer',
            ]
        );

        // Admin for Mezban Business app — POST /api/auth/login
        User::query()->updateOrCreate(
            ['email' => 'admin@mezban.com'],
            [
                'name' => 'Mezban Admin',
                'password' => 'password',
                'role' => 'admin',
            ]
        );
    }
}
