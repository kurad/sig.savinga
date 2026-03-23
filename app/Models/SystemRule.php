<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemRule extends Model
{
    protected $fillable = [
        'contribution_type',
        'contribution_amount',
        'contribution_frequency',
        'loan_interest_rate',
        'loan_limit_multiplier',

        'late_contribution_penalty',
        'missed_contribution_penalty',
        'late_loan_penalty',

        'late_contribution_penalty_percent',
        'missed_contribution_penalty_percent',
        'late_loan_penalty_percent',

        'profit_share_method',
        'contribution_cycle_months',
        'cycle_anchor_period',
        'contribution_due_day',
        'contribution_min_amount',
        'allow_overpay',
        'allow_underpay',
        'underpay_policy',
        'loan_limit_type',
        'loan_limit_value',
        'min_contribution_months',
        'allow_multiple_active_loans',
        'loan_default_repayment_mode',
        'loan_eligibility_basis',
        'allow_loan_top_up',
        'min_installments_before_top_up',
        'loan_installment_penalty_type',
        'loan_installment_penalty_value',
    ];

    protected $casts = [
        'contribution_amount' => 'decimal:2',
        'loan_interest_rate' => 'decimal:2',
        'late_contribution_penalty' => 'decimal:2',
        'missed_contribution_penalty' => 'decimal:2',
        'late_loan_penalty' => 'decimal:2',

        'late_contribution_penalty_percent' => 'decimal:2',
        'missed_contribution_penalty_percent' => 'decimal:2',
        'late_loan_penalty_percent' => 'decimal:2',

        'contribution_min_amount' => 'decimal:2',
        'loan_limit_value' => 'decimal:2',
        'loan_installment_penalty_value' => 'decimal:2',

        'allow_overpay' => 'boolean',
        'allow_underpay' => 'boolean',
        'allow_multiple_active_loans' => 'boolean',
        'allow_loan_top_up' => 'boolean',
    ];

    public static function singleton(): self
    {
        return static::query()->first() ?? static::query()->create([
            'contribution_type' => 'fixed',
            'contribution_amount' => 1000,
            'contribution_frequency' => 'weekly',
            'loan_interest_rate' => 10,
            'loan_limit_multiplier' => 3,

            'late_contribution_penalty' => 500,
            'missed_contribution_penalty' => 1000,
            'late_loan_penalty' => 2000,

            'late_contribution_penalty_percent' => 5,
            'missed_contribution_penalty_percent' => 10,
            'late_loan_penalty_percent' => 8,

            'profit_share_method' => 'savings_ratio',
            'contribution_cycle_months' => 12,
            'cycle_anchor_period' => '2024-01',
            'contribution_due_day' => 25,
            'contribution_min_amount' => 0,
            'allow_overpay' => true,
            'allow_underpay' => true,
            'underpay_policy' => 'warn',
            'loan_limit_type' => 'multiple',
            'loan_limit_value' => 3,
            'min_contribution_months' => 0,
            'allow_multiple_active_loans' => false,
            'loan_default_repayment_mode' => 'once',
            'loan_eligibility_basis' => 'total_contributions',
            'allow_loan_top_up' => true,
            'min_installments_before_top_up' => 3,
            'loan_installment_penalty_type' => 'percent_of_installment',
            'loan_installment_penalty_value' => 0,
        ]);
    }
}