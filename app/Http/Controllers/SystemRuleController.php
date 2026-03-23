<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSystemRuleRequest;
use App\Models\SystemRule;
use Illuminate\Http\JsonResponse;

class SystemRuleController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => SystemRule::singleton(),
        ]);
    }
    public function update(UpdateSystemRuleRequest $request): JsonResponse
    {
        $rules = SystemRule::singleton();
        $data = $request->validated();

        $updates = [];

        if (array_key_exists('contribution_type', $data)) {
            $updates['contribution_type'] = $data['contribution_type'];
        }

        if (array_key_exists('contribution_amount', $data)) {
            $updates['contribution_amount'] = is_null($data['contribution_amount'])
                ? null
                : round((float) $data['contribution_amount'], 2);
        }

        if (array_key_exists('contribution_frequency', $data)) {
            $updates['contribution_frequency'] = $data['contribution_frequency'];
        }

        if (array_key_exists('loan_interest_rate', $data)) {
            $updates['loan_interest_rate'] = round((float) $data['loan_interest_rate'], 2);
        }

        if (array_key_exists('loan_limit_multiplier', $data)) {
            $updates['loan_limit_multiplier'] = (int) $data['loan_limit_multiplier'];
        }

        if (array_key_exists('late_contribution_penalty', $data)) {
            $updates['late_contribution_penalty'] = round((float) ($data['late_contribution_penalty'] ?? 0), 2);
        }

        if (array_key_exists('missed_contribution_penalty', $data)) {
            $updates['missed_contribution_penalty'] = round((float) ($data['missed_contribution_penalty'] ?? 0), 2);
        }

        if (array_key_exists('late_loan_penalty', $data)) {
            $updates['late_loan_penalty'] = round((float) ($data['late_loan_penalty'] ?? 0), 2);
        }

        if (array_key_exists('late_contribution_penalty_percent', $data)) {
            $updates['late_contribution_penalty_percent'] = round((float) $data['late_contribution_penalty_percent'], 2);
        }

        if (array_key_exists('missed_contribution_penalty_percent', $data)) {
            $updates['missed_contribution_penalty_percent'] = round((float) $data['missed_contribution_penalty_percent'], 2);
        }

        if (array_key_exists('late_loan_penalty_percent', $data)) {
            $updates['late_loan_penalty_percent'] = round((float) $data['late_loan_penalty_percent'], 2);
        }

        if (array_key_exists('profit_share_method', $data)) {
            $updates['profit_share_method'] = $data['profit_share_method'];
        }

        if (array_key_exists('contribution_cycle_months', $data)) {
            $updates['contribution_cycle_months'] = (int) $data['contribution_cycle_months'];
        }

        if (array_key_exists('cycle_anchor_period', $data)) {
            $updates['cycle_anchor_period'] = $data['cycle_anchor_period'];
        }

        if (array_key_exists('contribution_due_day', $data)) {
            $updates['contribution_due_day'] = (int) $data['contribution_due_day'];
        }

        if (array_key_exists('contribution_min_amount', $data)) {
            $updates['contribution_min_amount'] = round((float) $data['contribution_min_amount'], 2);
        }

        if (array_key_exists('allow_overpay', $data)) {
            $updates['allow_overpay'] = (bool) $data['allow_overpay'];
        }

        if (array_key_exists('allow_underpay', $data)) {
            $updates['allow_underpay'] = (bool) $data['allow_underpay'];
        }

        if (array_key_exists('underpay_policy', $data)) {
            $updates['underpay_policy'] = $data['underpay_policy'];
        }

        if (array_key_exists('loan_limit_type', $data)) {
            $updates['loan_limit_type'] = $data['loan_limit_type'];
        }

        if (array_key_exists('loan_limit_value', $data)) {
            $updates['loan_limit_value'] = round((float) $data['loan_limit_value'], 2);
        }

        if (array_key_exists('min_contribution_months', $data)) {
            $updates['min_contribution_months'] = (int) $data['min_contribution_months'];
        }

        if (array_key_exists('allow_multiple_active_loans', $data)) {
            $updates['allow_multiple_active_loans'] = (bool) $data['allow_multiple_active_loans'];
        }

        if (array_key_exists('loan_default_repayment_mode', $data)) {
            $updates['loan_default_repayment_mode'] = $data['loan_default_repayment_mode'];
        }

        if (array_key_exists('loan_eligibility_basis', $data)) {
            $updates['loan_eligibility_basis'] = $data['loan_eligibility_basis'];
        }

        if (array_key_exists('allow_loan_top_up', $data)) {
            $updates['allow_loan_top_up'] = (bool) $data['allow_loan_top_up'];
        }

        if (array_key_exists('min_installments_before_top_up', $data)) {
            $updates['min_installments_before_top_up'] = (int) $data['min_installments_before_top_up'];
        }

        if (array_key_exists('loan_installment_penalty_type', $data)) {
            $updates['loan_installment_penalty_type'] = $data['loan_installment_penalty_type'];
        }

        if (array_key_exists('loan_installment_penalty_value', $data)) {
            $updates['loan_installment_penalty_value'] = round((float) $data['loan_installment_penalty_value'], 2);
        }

        $rules->update($updates);

        return response()->json([
            'message' => 'System rules updated successfully.',
            'data' => $rules->fresh(),
        ]);
    }
}
