<?php

namespace Database\Seeders;

use App\Models\ProfitCycle;
use Illuminate\Database\Seeder;

class ProfitCycleSeeder extends Seeder
{
    public function run(): void
    {
        if (ProfitCycle::where('status', 'open')->exists()) {
            return;
        }

        ProfitCycle::create([
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => 'open',
            'opened_by' => 1, // chair
        ]);
    }
}
