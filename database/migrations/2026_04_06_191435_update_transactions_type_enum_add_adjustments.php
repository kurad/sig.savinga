<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE transactions 
            MODIFY COLUMN type ENUM(
                'opening_balance',
                'opening_loan',
                'contribution',
                'loan_disbursement',
                'loan_repayment',
                'penalty',
                'profit',
                'penalty_paid',
                'penalty_waived',
                'loan_interest_deducted',
                'expense',
                'contribution_reversal',
                'penalty_reversal',
                'investment',
                'investment_sale',
                'registration_fee',
                'donation',
                'fine',
                'other',
                'opening_balance_adjustment',
                'contribution_adjustment',
                'loan_adjustment'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE transactions 
            MODIFY COLUMN type ENUM(
                'opening_balance',
                'opening_loan',
                'contribution',
                'loan_disbursement',
                'loan_repayment',
                'penalty',
                'profit',
                'penalty_paid',
                'penalty_waived',
                'loan_interest_deducted',
                'expense',
                'contribution_reversal',
                'penalty_reversal',
                'investment',
                'investment_sale',
                'registration_fee',
                'donation',
                'fine',
                'other',
                'opening_balance_adjustment',
                'contribution_adjustment',
                'loan_adjustment'
            ) NOT NULL
        ");
    }
};