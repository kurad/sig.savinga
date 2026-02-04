<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SystemRulesSeeder::class,
            CoreUsersSeeder::class,
            ProfitCycleSeeder::class,

            // Comment out in production
            DemoMembersSeeder::class,
        ]);
    }
}
