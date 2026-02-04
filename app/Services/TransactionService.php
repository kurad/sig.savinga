<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Exception;

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
        ?int $sourceId = null
    ): Transaction {
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
                'reference' => $reference,
                'source_type' => $sourceType,
                'source_id'  => $sourceId,
                'created_by' => $createdBy
            ]);
        
    }
    public function groupBalance(): float
    {
        $credit = (float) Transaction::sum('credit');
        $debit  = (float) Transaction::sum('debit');

        return $credit - $debit;
    }
    public function memberSavings(int $userId): float
    {
        return (float) Transaction::where('user_id', $userId)
            ->whereIn('type', ['contribution', 'profit'])
            ->sum('credit');
    }
/**
     * Member net balance across all ledger entries (credits - debits).
     * Useful for statement totals.
     */
    public function memberNetBalance(int $userId): float
    {
        $credit = (float) Transaction::where('user_id', $userId)->sum('credit');
        $debit  = (float) Transaction::where('user_id', $userId)->sum('debit');

        return $credit - $debit;
    }
    public function memberLoanBalance(int $userId): float
    {
        $loanDisbursed =(float) Transaction::where('user_id', $userId)
            ->where('type', 'loan_disbursement')
            ->sum('debit');

        $loanRepaid =(float) Transaction::where('user_id', $userId)
            ->where('type', 'loan_repayment')
            ->sum('credit');

        return $loanDisbursed - $loanRepaid;
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
    public function memberPenaltyOutstanding(int $userId): float
    {
        $assessed = (float) Transaction::where('user_id', $userId)
            ->where('type', 'penalty')
            ->sum('credit');

        $cleared = (float) Transaction::where('user_id', $userId)
            ->whereIn('type', ['penalty_paid', 'penalty_waived'])
            ->sum('debit');

        return $assessed - $cleared;
    }
   
    public function totalProfitAccrual(): float
    {
        $loanRepayments = (float) Transaction::where('type', 'loan_repayment')->sum('credit');
        $loanDisbursed  = (float) Transaction::where('type', 'loan_disbursement')->sum('debit');

        $penaltiesAssessed = (float) Transaction::where('type', 'penalty')->sum('credit');
        $penaltiesWaived   = (float) Transaction::where('type', 'penalty_waived')->sum('debit');

        $explicitProfit = (float) Transaction::where('type', 'profit')->sum('credit');

        return ($loanRepayments - $loanDisbursed)
            + ($penaltiesAssessed - $penaltiesWaived)
            + $explicitProfit;
    }
}
