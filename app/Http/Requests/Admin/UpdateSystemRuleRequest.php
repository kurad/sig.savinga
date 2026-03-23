<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && in_array($user->role, ['admin', 'treasurer'], true);
    }

    public function rules(): array
    {
        return [
            'contribution_type' => ['sometimes', 'in:fixed,flexible'],
            'contribution_amount' => ['sometimes','nullable', 'numeric', 'min:0'],
            'contribution_frequency' => ['sometimes', 'in:weekly,monthly'],

            'loan_interest_rate' => ['sometimes', 'numeric', 'min:0'],
            'loan_limit_multiplier' => ['sometimes', 'integer', 'min:1'],

            'late_contribution_penalty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'missed_contribution_penalty' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'late_loan_penalty' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'late_contribution_penalty_percent' => ['sometimes', 'numeric', 'min:0'],
            'missed_contribution_penalty_percent' => ['sometimes', 'numeric', 'min:0'],
            'late_loan_penalty_percent' => ['sometimes', 'numeric', 'min:0'],

            'profit_share_method' => ['sometimes', 'in:equal,savings_ratio'],

            'contribution_cycle_months' => ['sometimes',  'integer', 'min:1'],
            'cycle_anchor_period' => ['sometimes',  'regex:/^\d{4}-\d{2}$/'],
            'contribution_due_day' => ['sometimes',  'integer', 'min:1', 'max:31'],
            'contribution_min_amount' => ['sometimes',  'numeric', 'min:0'],

            'allow_overpay' => ['sometimes',  'boolean'],
            'allow_underpay' => ['sometimes',  'boolean'],
            'underpay_policy' => ['sometimes',  'in:none,warn,penalize,carry_forward'],

            'loan_limit_type' => ['sometimes',  'in:multiple,equal,fixed'],
            'loan_limit_value' => ['sometimes',  'numeric', 'min:0'],
            'min_contribution_months' => ['sometimes',  'integer', 'min:0'],
            'allow_multiple_active_loans' => ['sometimes',  'boolean'],
            'loan_default_repayment_mode' => ['sometimes',  'in:once,installment'],
            'loan_eligibility_basis' => ['sometimes',  'in:total_contributions,net_contributions'],
            'allow_loan_top_up' => ['sometimes',  'boolean'],
            'min_installments_before_top_up' => ['sometimes',  'integer', 'min:0'],
            'loan_installment_penalty_type' => ['sometimes',  'string', 'max:255'],
            'loan_installment_penalty_value' => ['sometimes',  'numeric', 'min:0'],
        ];
    }
}