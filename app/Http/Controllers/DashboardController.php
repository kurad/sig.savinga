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

        $apply = function ($q, string $col = 'created_at') use ($fromDt, $toDt) {
            if ($fromDt) $q->where($col, '>=', $fromDt);
            if ($toDt)   $q->where($col, '<=', $toDt);
            return $q;
        };

        // ===== Ledger totals (date filtered) =====
        $txQ = Transaction::query();
        $apply($txQ);

        $tx = $txQ->get(['type','debit','credit']);

        $contribIn = (float) $tx->where('type','contribution')->sum('credit');
        $repayIn   = (float) $tx->where('type','loan_repayment')->sum('credit');
        $profitIn  = (float) $tx->where('type','profit')->sum('credit');
        $penaltyIn = (float) $tx->where('type','penalty_paid')->sum('credit');

        $loanOut   = (float) $tx->where('type','loan_disbursement')->sum('debit');

        // Expenses (date filtered)
        $expQ = Expense::query();
        $apply($expQ, 'expense_date'); // change column if yours differs
        $expensesTotal = (float) $expQ->sum('amount');

        // ===== Cash balance (NOT date filtered; current) =====
        // If you want current cash: sum all credits - sum all debits - total expenses
        $allTx = Transaction::query()->get(['debit','credit']);
        $allCredits = (float) $allTx->sum('credit');
        $allDebits  = (float) $allTx->sum('debit');
        $allExpenses = (float) Expense::query()->sum('amount'); // full history
        $cashBalance = $allCredits - $allDebits - $allExpenses;

        // ===== Loans outstanding (current) =====
        $activeLoans = Loan::where('status','active')->get();
        $outstandingTotal = (float) $activeLoans->sum(fn($l) => $l->outstandingBalance());
        $overdueLoansCount = (int) Loan::where('status','active')
            ->whereDate('due_date','<', now()->toDateString())
            ->count();

        // ===== Penalties (current) =====
        $unpaidPenalties = (int) Penalty::where('status','unpaid')->count();
        $unpaidPenaltyAmount = (float) Penalty::where('status','unpaid')->sum('amount');

        // ===== Profit cycle (current) =====
        $openCycle = ProfitCycle::where('status','open')->latest()->first();
        $openCycleId = $openCycle?->id;

        // ===== Recent transactions (current) =====
        $recent = Transaction::query()
            ->latest()
            ->limit(10)
            ->get(['id','type','debit','credit','reference','created_at']);

        return response()->json([
            'filters' => ['from' => $from, 'to' => $to],

            'kpis' => [
                'cash_balance' => round($cashBalance, 2),

                'period' => [
                    'contributions_in' => round($contribIn, 2),
                    'loan_repayments_in' => round($repayIn, 2),
                    'profit_in' => round($profitIn, 2),
                    'penalties_in' => round($penaltyIn, 2),
                    'loan_disbursed_out' => round($loanOut, 2),
                    'expenses_out' => round($expensesTotal, 2),
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
