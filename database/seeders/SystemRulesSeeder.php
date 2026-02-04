<?php

namespace Database\Seeders;

use App\Models\SystemRule;
use Illuminate\Database\Seeder;

class SystemRulesSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure only ONE row exists
        SystemRule::query()->delete();

        SystemRule::create([
            'contribution_type' => 'fixed',
            'contribution_amount' => 1000, // RWF
            'contribution_frequency' => 'weekly',

            'loan_interest_rate' => 10, // %
            'loan_limit_multiplier' => 3,

            'late_contribution_penalty' => 500,
            'missed_contribution_penalty' => 1000,
            'late_loan_penalty' => 2000,

            'profit_share_method' => 'savings_ratio',
        ]);
    }
}
