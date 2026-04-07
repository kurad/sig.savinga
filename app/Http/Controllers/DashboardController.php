<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Penalty;
use App\Models\Transaction;
use App\Models\ProfitCycle;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $from = $request->query('from');
        $to   = $request->query('to');

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt   = $to ? Carbon::parse($to)->endOfDay() : null;

        $applyRange = function ($q, string $col = 'created_at') use ($fromDt, $toDt) {
            if ($fromDt) {
                $q->where($col, '>=', $fromDt);
            }
            if ($toDt) {
                $q->where($col, '<=', $toDt);
            }
            return $q;
        };

        $isPrivileged = in_array($user->role, ['admin', 'treasurer'], true);

        // ============================
        // Period ledger totals (NET)
        // ============================
        $periodTxQ = Transaction::query();
        $applyRange($periodTxQ, 'created_at');

        if (!$isPrivileged) {
            $periodTxQ->where('user_id', $user->id);
        }

        $periodAgg = $periodTxQ->selectRaw("
            COALESCE(SUM(CASE WHEN type IN ('contribution', 'contribution_adjustment') THEN credit ELSE 0 END), 0) AS contrib_credit,
            COALESCE(SUM(CASE WHEN type IN ('contribution', 'contribution_adjustment', 'contribution_reversal', 'contribution_undo') THEN debit ELSE 0 END), 0) AS contrib_debit,

            COALESCE(SUM(CASE WHEN type = 'loan_repayment' THEN credit ELSE 0 END), 0) AS repay_credit,
            COALESCE(SUM(CASE WHEN type IN ('loan_repayment_reversal', 'loan_repayment_undo') THEN debit ELSE 0 END), 0) AS repay_debit,

            COALESCE(SUM(CASE WHEN type = 'profit' THEN credit ELSE 0 END), 0) AS profit_credit,
            COALESCE(SUM(CASE WHEN type IN ('profit_reversal', 'profit_undo') THEN debit ELSE 0 END), 0) AS profit_debit,

            COALESCE(SUM(CASE WHEN type = 'penalty_paid' THEN credit ELSE 0 END), 0) AS penalty_credit,
            COALESCE(SUM(CASE WHEN type IN ('penalty_paid', 'penalty_paid_reversal', 'penalty_paid_undo') THEN debit ELSE 0 END), 0) AS penalty_debit,

            COALESCE(SUM(CASE WHEN type IN ('loan_disbursement', 'loan_adjustment') THEN debit ELSE 0 END), 0) AS loan_debit,
            COALESCE(SUM(CASE WHEN type IN ('loan_adjustment', 'loan_disbursement_reversal', 'loan_disbursement_undo') THEN credit ELSE 0 END), 0) AS loan_credit
        ")->first();

        $contribNet = (float) $periodAgg->contrib_credit - (float) $periodAgg->contrib_debit;
        $repayNet   = (float) $periodAgg->repay_credit   - (float) $periodAgg->repay_debit;
        $profitNet  = (float) $periodAgg->profit_credit  - (float) $periodAgg->profit_debit;
        $penaltyNet = (float) $periodAgg->penalty_credit - (float) $periodAgg->penalty_debit;
        $loanOutNet = (float) $periodAgg->loan_debit     - (float) $periodAgg->loan_credit;

        // ============================
        // Expenses (period filtered)
        // ============================
        // Expense is already a ledger debit in your current design,
        // so period expenses are derived from transactions instead of Expense model.
        $periodExpenseQ = Transaction::query()
            ->where('type', 'expense');

        $applyRange($periodExpenseQ, 'created_at');

        if (!$isPrivileged) {
            $periodExpenseQ->where('user_id', $user->id);
        }

        $expensesTotal = (float) $periodExpenseQ->sum('debit');

        // ============================
        // Cash balance (current, full history)
        // ============================
        $cashBalance = 0.0;

        if ($isPrivileged) {
            $cashAgg = Transaction::query()
                ->selectRaw("
                    COALESCE(SUM(credit), 0) AS total_credit,
                    COALESCE(SUM(debit), 0) AS total_debit
                ")
                ->first();

            $cashBalance = (float) $cashAgg->total_credit - (float) $cashAgg->total_debit;
        } else {
            $memberCashAgg = Transaction::query()
                ->where('user_id', $user->id)
                ->selectRaw("
                    COALESCE(SUM(credit), 0) AS total_credit,
                    COALESCE(SUM(debit), 0) AS total_debit
                ")
                ->first();

            $cashBalance = (float) $memberCashAgg->total_credit - (float) $memberCashAgg->total_debit;
        }

        // ============================
        // Loans outstanding (current)
        // ============================
        $activeLoansQ = Loan::where('status', 'active');

        if (!$isPrivileged) {
            $activeLoansQ->where('user_id', $user->id);
        }

        $activeLoans = $activeLoansQ->get();

        $outstandingTotal = (float) $activeLoans->sum(fn ($l) => $l->outstandingBalance());
        $outstandingPrincipal = (float) $activeLoans->sum(fn ($l) => $l->outstandingPrincipal());
        $outstandingInterest = (float) $activeLoans->sum(fn ($l) => $l->outstandingInterest());

        $overdueLoansCountQ = Loan::where('status', 'active')
            ->whereDate('due_date', '<', now()->toDateString());

        if (!$isPrivileged) {
            $overdueLoansCountQ->where('user_id', $user->id);
        }

        $overdueLoansCount = (int) $overdueLoansCountQ->count();

        // ============================
        // Penalties (current)
        // ============================
        $penaltyQ = Penalty::where('status', 'unpaid');

        if (!$isPrivileged) {
            $penaltyQ->where('user_id', $user->id);
        }

        $unpaidPenalties = (int) (clone $penaltyQ)->count();
        $unpaidPenaltyAmount = (float) (clone $penaltyQ)->sum('amount');

        // ============================
        // Profit cycle (current)
        // ============================
        $openCycle = $isPrivileged
            ? ProfitCycle::where('status', 'open')->latest()->first()
            : null;

        $openCycleId = $openCycle?->id;

        // ============================
        // Recent transactions (current)
        // ============================
        $recentQ = Transaction::query();

        if (!$isPrivileged) {
            $recentQ->where('user_id', $user->id);
        }

        $recent = $recentQ
            ->latest()
            ->limit(10)
            ->get(['id', 'user_id', 'beneficiary_id', 'type', 'debit', 'credit', 'reference', 'created_at']);

        return response()->json([
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],

            'kpis' => [
                'cash_balance' => round($cashBalance, 2),

                'period' => [
                    'contributions_in'   => round($contribNet, 2),
                    'loan_repayments_in' => round($repayNet, 2),
                    'profit_in'          => round($profitNet, 2),
                    'penalties_in'       => round($penaltyNet, 2),
                    'loan_disbursed_out' => round($loanOutNet, 2),
                    'expenses_out'       => round($expensesTotal, 2),
                ],

                'loans' => [
                    'outstanding_total'     => round($outstandingTotal, 2),
                    'outstanding_principal' => round($outstandingPrincipal, 2),
                    'outstanding_interest'  => round($outstandingInterest, 2),
                    'overdue_count'         => $overdueLoansCount,
                ],

                'penalties' => [
                    'unpaid_count' => $unpaidPenalties,
                    'unpaid_amount' => round($unpaidPenaltyAmount, 2),
                ],

                'profit_cycle' => [
                    'open_cycle_id' => $openCycleId,
                    'open_cycle_start' => $openCycle?->start_date,
                    'open_cycle_end' => $openCycle?->end_date,
                    'status' => $openCycle ? 'open' : 'none',
                ],
            ],

            'recent_transactions' => $recent,
        ]);
    }
}