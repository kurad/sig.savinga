<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Penalty;
use App\Models\ProfitCycle;
use App\Models\Transaction;
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

        $isPrivileged = in_array($user->role, ['admin', 'treasurer'], true);

        $applyRange = function ($query, string $column = 'created_at') use ($fromDt, $toDt) {
            if ($fromDt) {
                $query->where($column, '>=', $fromDt);
            }

            if ($toDt) {
                $query->where($column, '<=', $toDt);
            }

            return $query;
        };

        $scopeUser = function ($query) use ($isPrivileged, $user) {
            if (!$isPrivileged) {
                $query->where('user_id', $user->id);
            }

            return $query;
        };

        // ============================
        // 1. Book Balance
        // All ledger transactions.
        // ============================
        $bookQ = Transaction::query();
        $scopeUser($bookQ);

        $bookAgg = $bookQ->selectRaw("
            COALESCE(SUM(credit), 0) AS total_credit,
            COALESCE(SUM(debit), 0) AS total_debit
        ")->first();

        $bookBalance = (float) $bookAgg->total_credit - (float) $bookAgg->total_debit;

        // ============================
        // 2. Cash on Hand
        // Only real cash movements.
        // Opening balances are NOT included here.
        // ============================
        $cashQ = Transaction::query();
        $scopeUser($cashQ);

        $cashAgg = $cashQ->selectRaw("
            COALESCE(SUM(CASE
                WHEN type IN (
                    'income',
                    'contribution',
                    'loan_repayment',
                    'penalty_paid',
                    'registration_fee',
                    'donation',
                    'fine',
                    'investment_sale'
                )
                THEN credit ELSE 0 END), 0) AS cash_in,

            COALESCE(SUM(CASE
                WHEN type IN (
                    'expense',
                    'loan_disbursement',
                    'investment'
                )
                THEN debit ELSE 0 END), 0) AS cash_out,

            COALESCE(SUM(CASE
                WHEN type IN (
                    'contribution_reversal',
                    'penalty_reversal'
                )
                THEN debit ELSE 0 END), 0) AS cash_in_reversed
        ")->first();

        $cashIn = (float) $cashAgg->cash_in;
        $cashOut = (float) $cashAgg->cash_out;
        $cashInReversed = (float) $cashAgg->cash_in_reversed;

        $cashOnHand = $cashIn - $cashInReversed - $cashOut;

        // ============================
        // 3. Member Funds
        // What members own in the system.
        // Opening balance belongs here.
        // ============================
        $memberFundsQ = Transaction::query();
        $scopeUser($memberFundsQ);

        $memberFundsAgg = $memberFundsQ->selectRaw("
            COALESCE(SUM(CASE
                WHEN type IN (
                    'opening_balance',
                    'opening_balance_adjustment',
                    'contribution',
                    'profit'
                )
                THEN credit ELSE 0 END), 0) AS funds_credit,

            COALESCE(SUM(CASE
                WHEN type IN (
                    'opening_balance',
                    'opening_balance_adjustment',
                    'contribution_reversal'
                )
                THEN debit ELSE 0 END), 0) AS funds_debit
        ")->first();

        $memberFunds = (float) $memberFundsAgg->funds_credit - (float) $memberFundsAgg->funds_debit;

        // ============================
        // 4. Cash Coverage Gap
        // Positive means cash can cover member funds.
        // Negative means recorded member funds exceed cash on hand.
        // ============================
        $cashCoverageGap = $cashOnHand - $memberFunds;

        // ============================
        // 5. Period Summary
        // ============================
        $periodTxQ = Transaction::query();
        $applyRange($periodTxQ);
        $scopeUser($periodTxQ);

        $periodAgg = $periodTxQ->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'contribution' THEN credit ELSE 0 END), 0) AS contribution_credit,
            COALESCE(SUM(CASE WHEN type = 'contribution_reversal' THEN debit ELSE 0 END), 0) AS contribution_debit,

            COALESCE(SUM(CASE WHEN type = 'loan_repayment' THEN credit ELSE 0 END), 0) AS repayment_credit,

            COALESCE(SUM(CASE WHEN type = 'penalty_paid' THEN credit ELSE 0 END), 0) AS penalty_credit,
            COALESCE(SUM(CASE WHEN type = 'penalty_reversal' THEN debit ELSE 0 END), 0) AS penalty_debit,

            COALESCE(SUM(CASE WHEN type = 'profit' THEN credit ELSE 0 END), 0) AS profit_credit,

            COALESCE(SUM(CASE WHEN type = 'income' THEN credit ELSE 0 END), 0) AS income_credit,

            COALESCE(SUM(CASE WHEN type = 'expense' THEN debit ELSE 0 END), 0) AS expense_debit,

            COALESCE(SUM(CASE WHEN type = 'loan_disbursement' THEN debit ELSE 0 END), 0) AS loan_disbursement_debit,

            COALESCE(SUM(CASE WHEN type = 'investment' THEN debit ELSE 0 END), 0) AS investment_debit,

            COALESCE(SUM(CASE WHEN type = 'investment_sale' THEN credit ELSE 0 END), 0) AS investment_sale_credit
        ")->first();

        $periodContributions = (float) $periodAgg->contribution_credit - (float) $periodAgg->contribution_debit;
        $periodRepayments = (float) $periodAgg->repayment_credit;
        $periodPenalties = (float) $periodAgg->penalty_credit - (float) $periodAgg->penalty_debit;
        $periodProfit = (float) $periodAgg->profit_credit;
        $periodIncome = (float) $periodAgg->income_credit;
        $periodExpenses = (float) $periodAgg->expense_debit;
        $periodLoanDisbursed = (float) $periodAgg->loan_disbursement_debit;
        $periodInvestmentOut = (float) $periodAgg->investment_debit;
        $periodInvestmentSale = (float) $periodAgg->investment_sale_credit;

        $periodNetCashFlow =
            $periodContributions
            + $periodRepayments
            + $periodPenalties
            + $periodIncome
            + $periodInvestmentSale
            - $periodExpenses
            - $periodLoanDisbursed
            - $periodInvestmentOut;

        // ============================
        // 6. Loans
        // ============================
        $activeLoansQ = Loan::where('status', 'active');
        $scopeUser($activeLoansQ);

        $activeLoans = $activeLoansQ->get();

        $outstandingTotal = (float) $activeLoans->sum(fn ($loan) => $loan->outstandingBalance());
        $outstandingPrincipal = (float) $activeLoans->sum(fn ($loan) => $loan->outstandingPrincipal());
        $outstandingInterest = (float) $activeLoans->sum(fn ($loan) => $loan->outstandingInterest());
        $activeLoansCount = (int) $activeLoans->count();

        $overdueLoansQ = Loan::where('status', 'active')
            ->whereDate('due_date', '<', now()->toDateString());

        $scopeUser($overdueLoansQ);

        $overdueLoansCount = (int) $overdueLoansQ->count();

        // ============================
        // 7. Penalties
        // ============================
        $penaltyQ = Penalty::where('status', 'unpaid');
        $scopeUser($penaltyQ);

        $unpaidPenalties = (int) (clone $penaltyQ)->count();
        $unpaidPenaltyAmount = (float) (clone $penaltyQ)->sum('amount');

        // ============================
        // 8. Profit Cycle
        // ============================
        $openCycle = $isPrivileged
            ? ProfitCycle::where('status', 'open')->latest()->first()
            : null;

        // ============================
        // 9. Recent Transactions
        // ============================
        $recentQ = Transaction::query();
        $scopeUser($recentQ);

        $recent = $recentQ
            ->latest()
            ->limit(10)
            ->get([
                'id',
                'user_id',
                'beneficiary_id',
                'type',
                'debit',
                'credit',
                'reference',
                'created_at',
            ]);

        return response()->json([
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],

            'kpis' => [
                'cash_on_hand' => round($cashOnHand, 2),
                'member_funds' => round($memberFunds, 2),
                'cash_coverage_gap' => round($cashCoverageGap, 2),
                'book_balance' => round($bookBalance, 2),

                // Keep old key temporarily so old frontend does not break.
                'cash_balance' => round($bookBalance, 2),

                'period' => [
                    'contributions_in' => round($periodContributions, 2),
                    'loan_repayments_in' => round($periodRepayments, 2),
                    'penalties_in' => round($periodPenalties, 2),
                    'profit_in' => round($periodProfit, 2),
                    'income_in' => round($periodIncome, 2),
                    'expenses_out' => round($periodExpenses, 2),
                    'loan_disbursed_out' => round($periodLoanDisbursed, 2),
                    'investment_out' => round($periodInvestmentOut, 2),
                    'investment_sale_in' => round($periodInvestmentSale, 2),
                    'net_cash_flow' => round($periodNetCashFlow, 2),
                ],

                'loans' => [
                    'outstanding_total' => round($outstandingTotal, 2),
                    'outstanding_principal' => round($outstandingPrincipal, 2),
                    'outstanding_interest' => round($outstandingInterest, 2),
                    'active_count' => $activeLoansCount,
                    'overdue_count' => $overdueLoansCount,
                ],

                'penalties' => [
                    'unpaid_count' => $unpaidPenalties,
                    'unpaid_amount' => round($unpaidPenaltyAmount, 2),
                ],

                'profit_cycle' => [
                    'open_cycle_id' => $openCycle?->id,
                    'open_cycle_start' => $openCycle?->start_date,
                    'open_cycle_end' => $openCycle?->end_date,
                    'status' => $openCycle ? 'open' : 'none',
                ],
            ],

            'recent_transactions' => $recent,
        ]);
    }
}