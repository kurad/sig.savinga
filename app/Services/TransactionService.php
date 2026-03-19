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
        ?int $userId,
        string $reference,
        int $createdBy,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $beneficiaryId = null
    ): Transaction {
        $this->validateOwner($userId, $beneficiaryId);

        $debit = round((float) $debit, 2);
        $credit = round((float) $credit, 2);

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

    protected function validateOwner(?int $userId, ?int $beneficiaryId): void
    {
        $hasUser = !is_null($userId);
        $hasBeneficiary = !is_null($beneficiaryId);

        if (($hasUser && $hasBeneficiary) || (!$hasUser && !$hasBeneficiary)) {
            throw new Exception('Transaction must belong to either a user or a beneficiary.');
        }
    }

    protected function ownerQuery(?int $userId, ?int $beneficiaryId): Builder
    {
        $this->validateOwner($userId, $beneficiaryId);

        return Transaction::query()
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId));
    }

    public function groupBalance(): float
    {
        $credit = (float) Transaction::sum('credit');
        $debit  = (float) Transaction::sum('debit');

        return $credit - $debit;
    }

    public function memberSavings(?int $userId = null, ?int $beneficiaryId = null): float
    {
        $credit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_balance', 'opening_savings', 'contribution', 'profit'])
            ->sum('credit');

        $debit = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_balance', 'opening_savings', 'contribution', 'profit'])
            ->sum('debit');

        return $credit - $debit;
    }

    public function memberSavingsBaseForLoanLimit(?int $userId = null, ?int $beneficiaryId = null): float
    {
        return (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_savings', 'opening_balance', 'contribution', 'profit'])
            ->sum('credit');
    }

    public function contributedMonthsCountFromLedger(?int $userId = null, ?int $beneficiaryId = null): int
    {
        return $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'contribution')
            ->distinct('reference')
            ->count('reference');
    }

    /**
     * Net balance across all ledger entries (credits - debits).
     */
    public function memberNetBalance(?int $userId = null, ?int $beneficiaryId = null): float
    {
        $credit = (float) $this->ownerQuery($userId, $beneficiaryId)->sum('credit');
        $debit  = (float) $this->ownerQuery($userId, $beneficiaryId)->sum('debit');

        return $credit - $debit;
    }

    public function memberLoanBalance(?int $userId = null, ?int $beneficiaryId = null): float
    {
        $loanDisbursed = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_loan', 'loan_disbursement'])
            ->sum('debit');

        $loanRepaid = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'loan_repayment')
            ->sum('credit');

        return max(0, $loanDisbursed - $loanRepaid);
    }

    public function memberLoanIssuedBase(?int $userId = null, ?int $beneficiaryId = null): float
    {
        return (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['opening_loan', 'loan_disbursement'])
            ->sum('debit');
    }

    public function memberLoanRepaidTotal(?int $userId = null, ?int $beneficiaryId = null): float
    {
        return (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'loan_repayment')
            ->sum('credit');
    }

    public function totalPenaltiesAssessed(): float
    {
        return (float) Transaction::where('type', 'penalty')->sum('credit');
    }

    /**
     * Total penalties outstanding = assessed - cleared (paid + waived).
     */
    public function totalPenaltyOutstanding(): float
    {
        $assessed = (float) Transaction::where('type', 'penalty')->sum('credit');

        $cleared = (float) Transaction::whereIn('type', ['penalty_paid', 'penalty_waived'])
            ->sum('debit');

        return $assessed - $cleared;
    }

    public function memberPenaltyOutstanding(?int $userId = null, ?int $beneficiaryId = null): float
    {
        $assessed = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->where('type', 'penalty')
            ->sum('credit');

        $cleared = (float) $this->ownerQuery($userId, $beneficiaryId)
            ->whereIn('type', ['penalty_paid', 'penalty_waived'])
            ->sum('debit');

        return $assessed - $cleared;
    }

    public function totalProfitAccrual(): float
    {
        $loanRepayments = (float) Transaction::where('type', 'loan_repayment')->sum('credit');
        $interestUpfront = (float) Transaction::where('type', 'loan_interest_deducted')->sum('credit');

        $loanDisbursed = (float) Transaction::where('type', 'loan_disbursement')->sum('debit');

        $penaltiesAssessed = (float) Transaction::where('type', 'penalty')->sum('credit');
        $penaltiesWaived = (float) Transaction::where('type', 'penalty_waived')->sum('debit');

        $explicitProfit = (float) Transaction::where('type', 'profit')->sum('credit');

        return ($loanRepayments + $interestUpfront - $loanDisbursed)
            + ($penaltiesAssessed - $penaltiesWaived)
            + $explicitProfit;
    }

    public function savingsBaseForLoanLimit(?int $userId = null, ?int $beneficiaryId = null): float
    {
        return 0.7 * $this->memberSavings($userId, $beneficiaryId);
    }

    public function availableCashBalance(): float
    {
        $credit = (float) Transaction::whereIn('type', [
            'opening_balance',
            'opening_savings',
            'contribution',
            'loan_repayment',
            'loan_interest_deducted',
            'penalty_paid',
            'investment_sale',
            'other_income',
        ])->sum('credit');

        $debit = (float) Transaction::whereIn('type', [
            'loan_disbursement',
            'expense',
            'investment',
            'withdrawal',
        ])->sum('debit');

        return $credit - $debit;
    }
}