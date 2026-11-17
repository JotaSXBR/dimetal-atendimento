<?php

namespace Database\Seeders;

use App\Services\Setup\SetupState;
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
        $this->call([
            SectorSeeder::class,
            TagSeeder::class,
            AdminUserSeeder::class,
            DefaultWorkflowSeeder::class,
        ]);

        SetupState::markConcluded();
    }
}
