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
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Static admin login for Mezban Business (change password in production).
        // Same API as hall owners: POST /api/auth/login { email, password }.
        User::query()->firstOrCreate(
            ['email' => 'admin@mezban.com'],
            [
                'name' => 'Mezban Admin',
                'password' => 'password',
                'role' => 'admin',
            ]
        );
    }
}
