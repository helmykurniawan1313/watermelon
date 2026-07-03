<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create Helmy
        User::updateOrCreate(
            ['email' => 'helmy@example.com'], // Checks if email exists to avoid duplicates
            [
                'name' => 'Helmy',
                'password' => Hash::make('password'),
            ]
        );

        // Create Rika
        User::updateOrCreate(
            ['email' => 'rika@example.com'],
            [
                'name' => 'Rika',
                'password' => Hash::make('password'),
            ]
        );
    }
}