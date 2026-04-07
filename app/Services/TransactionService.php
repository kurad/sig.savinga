<?php

namespace App\Services;

use App\Models\Transaction;
use Exception;
use Illuminate\Database\Eloquent\Builder;

class TransactionService
{
    public function record(
        string $type,
        float $debit,
        float $credit,
        int $userId,
        string $reference,
        int $createdBy,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $beneficiaryId = null
    ): Transaction {
        $this->validateOwner($userId, $beneficiaryId);

        $debit = round((float) $debit, 2);
        $credit = round((float) $credit, 2);

        if ($debit < 0 || $credit < 0) {
            throw new Exception('Debit and credit amounts cannot be negative.');
        }

        if ($debit > 0 && $credit > 0) {
            throw new Exception('A transaction cannot have both debit and credit amounts greater than zero.');
        }

        if ($debit <= 0 && $credit <= 0) {
            throw new Exception('A transaction must have either a debit or credit amount greater than zero.');
        }

        return Transaction::create([
            'type' => $type,
            'debit' => $debit,
            'credit' => $credit,
            'user_id' => $userId,
            'beneficiary_id' => $beneficiaryId,
            'reference' => $reference,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $createdBy,
        ]);
    }

    protected function validateOwner(int $userId, ?int $beneficiaryId): void
    {
        if ($userId <= 0) {
            throw new Exception('Transaction user_id is required and must be a valid positive integer.');
        }

        if (!is_null($beneficiaryId) && $beneficiaryId <= 0) {
            throw new Exception('Transaction beneficiary_id must be a valid positive integer when provided.');
        }
    }

    protected function ownerQuery(int $userId, ?int $beneficiaryId = null): Builder
    {
        $this->validateOwner($userId, $beneficiaryId);

        $query = Transaction::query()->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            $query->whereNull('beneficiary_id');
        } else {
            $query->where('beneficiary_id', $beneficiaryId);
        }

        return $query;
    }

    public function groupBalance(): float
    {
        $credit = (float) Transaction::sum('credit');
        $debit  = (float) Transaction::sum('debit');

        return round($credit - $debit, 2);
    }

    public function memberSavings(int $userId, ?int $beneficiaryId = null): float
    {
        $types = [
            'opening_balance',
            'opening_balance_adjustment',
            'opening_savings',
            'contribution',
            'contribution_adjustment',
            'profit',
        ];

        $credit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', $types)
            ->sum('credit');

        $debit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', $types)
            ->sum('debit');

        return round($credit - $debit, 2);
    }

    public function memberSavingsBaseForLoanLimit(int $userId, ?int $beneficiaryId = null): float
    {
        $types = [
            'opening_savings',
            'opening_balance',
            'opening_balance_adjustment',
            'contribution',
            'contribution_adjustment',
            'profit',
        ];

        $credit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', $types)
            ->sum('credit');

        $debit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', $types)
            ->sum('debit');

        return round($credit - $debit, 2);
    }

    public function contributedMonthsCountFromLedger(int $userId, ?int $beneficiaryId = null): int
    {
        return (int) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['contribution', 'contribution_adjustment'])
            ->distinct('reference')
            ->count('reference');
    }

    public function memberNetBalance(int $userId, ?int $beneficiaryId = null): float
    {
        $credit = (float) $this->ownerQuery($userId, $beneficiaryId)->sum('credit');
        $debit  = (float) $this->ownerQuery($userId, $beneficiaryId)->sum('debit');

        return round($credit - $debit, 2);
    }

    public function memberLoanBalance(int $userId, ?int $beneficiaryId = null): float
    {
        $loanDisbursed = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_loan', 'loan_disbursement', 'loan_adjustment'])
            ->sum('debit');

        $loanRepaid = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'loan_repayment')
            ->sum('credit');

        $loanAdjustmentsPositive = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'loan_adjustment')
            ->sum('credit');

        return round(max(0, ($loanDisbursed - $loanRepaid) - $loanAdjustmentsPositive), 2);
    }

    public function memberLoanIssuedBase(int $userId, ?int $beneficiaryId = null): float
    {
        $debit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_loan', 'loan_disbursement', 'loan_adjustment'])
            ->sum('debit');

        $credit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'loan_adjustment')
            ->sum('credit');

        return round($debit - $credit, 2);
    }

    public function memberLoanRepaidTotal(int $userId, ?int $beneficiaryId = null): float
    {
        return round((float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'loan_repayment')
            ->sum('credit'), 2);
    }

    public function totalPenaltiesAssessed(): float
    {
        return round((float) Transaction::where('type', 'penalty')->sum('credit'), 2);
    }

    public function totalPenaltyOutstanding(): float
    {
        $assessed = (float) Transaction::where('type', 'penalty')->sum('credit');

        $cleared = (float) Transaction::whereIn('type', ['penalty_paid', 'penalty_waived'])
            ->sum('debit');

        return round($assessed - $cleared, 2);
    }

    public function memberPenaltyOutstanding(int $userId, ?int $beneficiaryId = null): float
    {
        $assessed = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'penalty')
            ->sum('credit');

        $cleared = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['penalty_paid', 'penalty_waived'])
            ->sum('debit');

        return round($assessed - $cleared, 2);
    }

    public function totalProfitAccrual(): float
    {
        $loanRepayments = (float) Transaction::where('type', 'loan_repayment')->sum('credit');
        $interestUpfront = (float) Transaction::where('type', 'loan_interest_deducted')->sum('credit');

        $loanDisbursed = (float) Transaction::where('type', 'loan_disbursement')->sum('debit');

        $loanAdjustmentCredits = (float) Transaction::where('type', 'loan_adjustment')->sum('credit');
        $loanAdjustmentDebits = (float) Transaction::where('type', 'loan_adjustment')->sum('debit');

        $penaltiesAssessed = (float) Transaction::where('type', 'penalty')->sum('credit');
        $penaltiesWaived = (float) Transaction::where('type', 'penalty_waived')->sum('debit');

        $explicitProfit = (float) Transaction::where('type', 'profit')->sum('credit');

        return round(
            ($loanRepayments + $interestUpfront - $loanDisbursed + $loanAdjustmentCredits - $loanAdjustmentDebits)
                + ($penaltiesAssessed - $penaltiesWaived)
                + $explicitProfit,
            2
        );
    }

    public function savingsBaseForLoanLimit(int $userId, ?int $beneficiaryId = null): float
    {
        return round(max(0, 0.7 * $this->memberSavings($userId, $beneficiaryId)), 2);
    }

    public function availableCashBalance(): float
    {
        $credit = (float) Transaction::whereIn('type', [
            'opening_balance',
            'opening_balance_adjustment',
            'opening_savings',
            'contribution',
            'contribution_adjustment',
            'loan_repayment',
            'loan_interest_deducted',
            'penalty_paid',
            'investment_sale',
            'other_income',
            'loan_adjustment',
        ])->sum('credit');

        $debit = (float) Transaction::whereIn('type', [
            'opening_balance',
            'opening_balance_adjustment',
            'opening_savings',
            'loan_disbursement',
            'expense',
            'investment',
            'withdrawal',
            'loan_adjustment',
        ])->sum('debit');

        return round($credit - $debit, 2);
    }
}
