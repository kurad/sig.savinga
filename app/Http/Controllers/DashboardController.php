<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Penalty;
use App\Models\Transaction;
use App\Models\Expense;
use App\Models\ProfitCycle;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->query('from');
        $to   = $request->query('to');

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt   = $to ? Carbon::parse($to)->endOfDay() : null;

        $applyRange = function ($q, string $col = 'created_at') use ($fromDt, $toDt) {
            if ($fromDt) $q->where($col, '>=', $fromDt);
            if ($toDt)   $q->where($col, '<=', $toDt);
            return $q;
        };

        // ============================
        // Period ledger totals (NET)
        // ============================
        $periodTxQ = Transaction::query();
        $applyRange($periodTxQ, 'created_at');

        // Map your reversal types here (adjust names to match your system)
        $periodAgg = $periodTxQ->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'contribution' THEN credit ELSE 0 END), 0) AS contrib_in,
            COALESCE(SUM(CASE WHEN type IN ('contribution_reversal','contribution_undo') THEN debit ELSE 0 END), 0) AS contrib_rev,

            COALESCE(SUM(CASE WHEN type = 'loan_repayment' THEN credit ELSE 0 END), 0) AS repay_in,
            COALESCE(SUM(CASE WHEN type IN ('loan_repayment_reversal','loan_repayment_undo') THEN debit ELSE 0 END), 0) AS repay_rev,

            COALESCE(SUM(CASE WHEN type = 'profit' THEN credit ELSE 0 END), 0) AS profit_in,
            COALESCE(SUM(CASE WHEN type IN ('profit_reversal','profit_undo') THEN debit ELSE 0 END), 0) AS profit_rev,

            COALESCE(SUM(CASE WHEN type = 'penalty_paid' THEN credit ELSE 0 END), 0) AS penalty_in,
            COALESCE(SUM(CASE WHEN type IN ('penalty_paid_reversal','penalty_paid_undo') THEN debit ELSE 0 END), 0) AS penalty_rev,

            COALESCE(SUM(CASE WHEN type = 'loan_disbursement' THEN debit ELSE 0 END), 0) AS loan_out,
            COALESCE(SUM(CASE WHEN type IN ('loan_disbursement_reversal','loan_disbursement_undo') THEN credit ELSE 0 END), 0) AS loan_out_rev
        ")->first();

        $contribNet = (float) $periodAgg->contrib_in - (float) $periodAgg->contrib_rev;
        $repayNet   = (float) $periodAgg->repay_in   - (float) $periodAgg->repay_rev;
        $profitNet  = (float) $periodAgg->profit_in  - (float) $periodAgg->profit_rev;
        $penaltyNet = (float) $periodAgg->penalty_in - (float) $periodAgg->penalty_rev;

        // Loan disbursed "out" net: original debits minus reversal credits
        $loanOutNet = (float) $periodAgg->loan_out - (float) $periodAgg->loan_out_rev;

        // Expenses (period filtered)
        $expQ = Expense::query();
        $applyRange($expQ, 'expense_date'); // change if your column differs
        $expensesTotal = (float) $expQ->sum('amount');

        // ============================
        // Cash balance (current, full history)
        // ============================
        // WARNING: This assumes expenses are NOT recorded in transactions.
        $cashAgg = Transaction::query()
            ->selectRaw("
                COALESCE(SUM(credit), 0) AS total_credit,
                COALESCE(SUM(debit), 0) AS total_debit
            ")
            ->first();

        $allExpenses = (float) Expense::query()->sum('amount');

        $cashBalance = (float) $cashAgg->total_credit - (float) $cashAgg->total_debit - $allExpenses;

        // ============================
        // Loans outstanding (current)
        // ============================
        $activeLoans = Loan::where('status', 'active')->get();
        $outstandingTotal = (float) $activeLoans->sum(fn ($l) => $l->outstandingBalance());

        $overdueLoansCount = (int) Loan::where('status', 'active')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        // ============================
        // Penalties (current)
        // ============================
        $unpaidPenalties = (int) Penalty::where('status', 'unpaid')->count();
        $unpaidPenaltyAmount = (float) Penalty::where('status', 'unpaid')->sum('amount');

        // ============================
        // Profit cycle (current)
        // ============================
        $openCycle = ProfitCycle::where('status', 'open')->latest()->first();
        $openCycleId = $openCycle?->id;

        // ============================
        // Recent transactions (current)
        // ============================
        $recent = Transaction::query()
            ->latest()
            ->limit(10)
            ->get(['id','type','debit','credit','reference','created_at']);

        return response()->json([
            'filters' => ['from' => $from, 'to' => $to],

            'kpis' => [
                'cash_balance' => round($cashBalance, 2),

                'period' => [
                    'contributions_in'     => round($contribNet, 2),
                    'loan_repayments_in'   => round($repayNet, 2),
                    'profit_in'            => round($profitNet, 2),
                    'penalties_in'         => round($penaltyNet, 2),
                    'loan_disbursed_out'   => round($loanOutNet, 2),
                    'expenses_out'         => round($expensesTotal, 2),
                ],

                'loans' => [
                    'outstanding_total' => round($outstandingTotal, 2),
                    'overdue_count' => $overdueLoansCount,
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