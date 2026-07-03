<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Ledger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Helmy
        $helmy = User::updateOrCreate(
            ['email' => 'helmy@example.com'],
            ['name' => 'Helmy', 'password' => Hash::make('password')]
        );

        // 2. Create Rika
        $rika = User::updateOrCreate(
            ['email' => 'rika@example.com'],
            ['name' => 'Rika', 'password' => Hash::make('password')]
        );

        // 3. Create the Main Ledger (ID 1)
        $ledger = Ledger::updateOrCreate(
            ['id' => 1],
            ['name' => 'Main Family Ledger', 'currency' => 'IDR']
        );

        // 4. Give them the "Keys" (Link users to the ledger)
        // This fixes the "Unauthorized" errors in your Controller
        $ledger->users()->sync([$helmy->id, $rika->id]);

        $this->command->info('Database ready: Helmy & Rika are linked to Ledger #1!');
    }
}