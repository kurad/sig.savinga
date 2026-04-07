<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\OpeningBalance;
use App\Models\Penalty;
use App\Models\ProfitDistribution;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class StatementService
{
    public function __construct(
        protected DueDateService $dueDateService,
        protected CommitmentService $commitmentService
    ) {}

    /**
     * Build member financial statement (with merged timeline)
     */
    public function memberStatement(User $viewer, User $member, ?string $from = null, ?string $to = null): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true) && $viewer->id !== $member->id) {
            throw new \Exception('Forbidden');
        }

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt   = $to ? Carbon::parse($to)->endOfDay() : null;

        $applyDateTime = function ($query, string $column = 'created_at') use ($fromDt, $toDt) {
            if ($fromDt) {
                $query->where($column, '>=', $fromDt);
            }
            if ($toDt) {
                $query->where($column, '<=', $toDt);
            }
            return $query;
        };

        /* =========================
           DATA SOURCES
           ========================= */

        // Opening balances
        $openingQ = OpeningBalance::query()
            ->where('user_id', $member->id)
            ->whereNull('beneficiary_id');

        if ($from) {
            $openingQ->where('as_of_period', '>=', Carbon::parse($from)->format('Y-m'));
        }
        if ($to) {
            $openingQ->where('as_of_period', '<=', Carbon::parse($to)->format('Y-m'));
        }

        $openingBalances = $openingQ
            ->with(['adjustments'])
            ->orderBy('as_of_period')
            ->get([
                'id', 'user_id', 'beneficiary_id', 'as_of_period', 'amount', 'note', 'transaction_id', 'created_by', 'created_at'
            ]);

        // Contributions
        $contribQ = Contribution::where('user_id', $member->id);
        if ($from) {
            $contribQ->whereDate('expected_date', '>=', $from);
        }
        if ($to) {
            $contribQ->whereDate('expected_date', '<=', $to);
        }

        $contributions = $contribQ->orderBy('expected_date')->get([
            'id', 'amount', 'expected_date', 'paid_date', 'status', 'penalty_amount', 'recorded_by', 'created_at'
        ]);

        // Loans
        $loanQ = Loan::where('user_id', $member->id);
        $applyDateTime($loanQ);

        $loans = $loanQ->orderBy('created_at')->get([
            'id', 'principal', 'total_payable', 'due_date', 'status', 'created_at'
        ]);

        // Repayments
        $repayQ = LoanRepayment::whereHas('loan', fn($q) => $q->where('user_id', $member->id));
        $applyDateTime($repayQ);

        $repayments = $repayQ->orderBy('created_at')->get([
            'id', 'loan_id', 'amount', 'principal_component', 'interest_component', 'repayment_date', 'recorded_by', 'created_at'
        ]);

        // Penalties
        $penQ = Penalty::where('user_id', $member->id);
        $applyDateTime($penQ);

        $penalties = $penQ->orderBy('created_at')->get([
            'id', 'source_type', 'source_id', 'amount', 'reason', 'status', 'created_at'
        ]);

        // Profit distributions
        $profitQ = ProfitDistribution::where('user_id', $member->id);
        $applyDateTime($profitQ);

        $profitDistributions = $profitQ->orderBy('created_at')->get([
            'id', 'profit_cycle_id', 'amount', 'distribution_type', 'status', 'created_at'
        ]);

        // Ledger transactions
        $txQ = Transaction::where('user_id', $member->id);
        $applyDateTime($txQ);

        $transactions = $txQ->orderBy('created_at')->get([
            'id', 'type', 'debit', 'credit', 'reference', 'source_type', 'source_id', 'created_at'
        ]);

        /* =========================
           OPENING BALANCE ENRICHMENT
           ========================= */

        $openingBalancesData = $openingBalances->map(function ($row) {
            $adjustmentsTotal = round((float) $row->adjustments->sum('amount'), 2);
            $originalAmount   = round((float) $row->amount, 2);
            $effectiveAmount  = round($originalAmount + $adjustmentsTotal, 2);

            return [
                'id'                => (int) $row->id,
                'as_of_period'      => $row->as_of_period,
                'original_amount'   => $originalAmount,
                'adjustments_total' => $adjustmentsTotal,
                'effective_amount'  => $effectiveAmount,
                'note'              => $row->note,
                'created_at'        => optional($row->created_at)->toDateTimeString(),
                'adjustments'       => $row->adjustments->map(function ($adj) {
                    return [
                        'id'         => (int) $adj->id,
                        'amount'     => round((float) $adj->amount, 2),
                        'reason'     => $adj->reason,
                        'created_by' => $adj->created_by,
                        'created_at' => optional($adj->created_at)->toDateTimeString(),
                    ];
                })->values(),
            ];
        })->values();

        $openingAdjustmentsTotal = round((float) $openingBalances->sum(function ($row) {
            return (float) $row->adjustments->sum('amount');
        }), 2);

        /* =========================
           SUMMARY (ledger-driven)
           ========================= */

        $contributionTypes = ['contribution', 'contribution_adjustment'];
        $openingTypes = ['opening_balance', 'opening_balance_adjustment'];
        $loanIssueTypes = ['loan_disbursement', 'loan_adjustment'];
        $profitTypes = ['profit'];

        $totalOpeningNet = round(
            (float) $transactions->whereIn('type', $openingTypes)->sum('credit')
            - (float) $transactions->whereIn('type', $openingTypes)->sum('debit'),
            2
        );

        $totalContribNet = round(
            (float) $transactions->whereIn('type', $contributionTypes)->sum('credit')
            - (float) $transactions->whereIn('type', $contributionTypes)->sum('debit'),
            2
        );

        $totalProfitNet = round(
            (float) $transactions->whereIn('type', $profitTypes)->sum('credit')
            - (float) $transactions->whereIn('type', $profitTypes)->sum('debit'),
            2
        );

        $totalLoanNet = round(
            (float) $transactions->whereIn('type', $loanIssueTypes)->sum('debit')
            - (float) $transactions->where('type', 'loan_adjustment')->sum('credit'),
            2
        );

        $totalRepayIn = round(
            (float) $transactions->where('type', 'loan_repayment')->sum('credit'),
            2
        );

        $totalPenaltiesCharged = round(
            (float) $transactions->where('type', 'penalty')->sum('credit'),
            2
        );

        $totalPenaltyPayments = round(
            (float) $transactions->where('type', 'penalty_paid')->sum('credit')
            - (float) $transactions->where('type', 'penalty_paid')->sum('debit'),
            2
        );

        $interestReceived = (float) $repayments->sum('interest_component');
        $principalRepaid  = (float) $repayments->sum('principal_component');

        $allLoansNow = Loan::where('user_id', $member->id)->get();
        $outstandingTotal     = (float) $allLoansNow->sum(fn($l) => $l->outstandingBalance());
        $outstandingPrincipal = (float) $allLoansNow->sum(fn($l) => $l->outstandingPrincipal());
        $outstandingInterest  = (float) $allLoansNow->sum(fn($l) => $l->outstandingInterest());

        /* =========================
           TIMELINE
           ========================= */

        $repaymentMap = $repayments->keyBy('id');

        $timeline = $transactions->map(function ($tx) use ($repaymentMap) {
            $direction = ((float) $tx->credit) > 0 ? 'in' : 'out';
            $amount = ((float) $tx->credit) > 0 ? (float) $tx->credit : (float) $tx->debit;

            $title = match ($tx->type) {
                'opening_balance'            => 'Opening Balance',
                'opening_balance_adjustment' => 'Opening Balance Adjustment',
                'contribution'               => 'Contribution',
                'contribution_adjustment'    => 'Contribution Adjustment',
                'loan_disbursement'          => 'Loan Disbursement',
                'loan_adjustment'            => 'Loan Adjustment',
                'loan_repayment'             => 'Loan Repayment',
                'penalty'                    => 'Penalty Charged',
                'penalty_paid'               => 'Penalty Payment',
                'penalty_waived'             => 'Penalty Waived',
                'profit'                     => ((float) $tx->credit) > 0 ? 'Profit Added to Savings' : 'Profit Payout',
                default                      => 'Transaction',
            };

            $item = [
                'date'      => $tx->created_at->toDateTimeString(),
                'category'  => $tx->type,
                'direction' => $direction,
                'amount'    => round($amount, 2),
                'debit'     => round((float) $tx->debit, 2),
                'credit'    => round((float) $tx->credit, 2),
                'title'     => $title,
                'reference' => $tx->reference,
                'meta'      => [],
            ];

            if ($tx->type === 'loan_repayment' && $tx->source_type === 'loan_repayment' && $tx->source_id) {
                $rep = $repaymentMap->get((int) $tx->source_id);
                if ($rep) {
                    $item['meta'] = [
                        'loan_id'             => (int) $rep->loan_id,
                        'principal_component' => round((float) $rep->principal_component, 2),
                        'interest_component'  => round((float) $rep->interest_component, 2),
                        'paid_date'           => $rep->repayment_date ? Carbon::parse($rep->repayment_date)->toDateString() : null,
                    ];
                }
            }

            return $item;
        })->values();

        /* =========================
           NEXT DUE
           ========================= */

        $todayPeriod = Carbon::now('Africa/Kigali')->format('Y-m');
        $commitmentToday = $this->commitmentService->activeForPeriod(
            $member->id,
            null,
            $todayPeriod
        );

        $nextDue = null;
        if ($commitmentToday) {
            $nextDue = $this->dueDateService->computeNextDueForMember(
                memberId: $member->id,
                commitmentAmount: (float) $commitmentToday->amount
            );
        } else {
            $fy = $this->dueDateService->getActiveYear();
            $nextDue = [
                'financial_year'  => $fy->year_key,
                'due_day'         => $fy->due_day,
                'grace_days'      => $fy->grace_days,
                'next_due_period' => $todayPeriod,
                'next_due_date'   => null,
                'days_remaining'  => null,
                'is_overdue'      => false,
                'hint'            => 'no_commitment',
            ];
        }

        return [
            'member' => [
                'id'    => $member->id,
                'name'  => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
            ],
            'filters' => [
                'from' => $from,
                'to'   => $to,
            ],
            'next_due' => $nextDue,
            'summary' => [
                'totals_from_ledger' => [
                    'opening_balance_net'       => $totalOpeningNet,
                    'opening_adjustments_total' => $openingAdjustmentsTotal,
                    'contributions_net'         => $totalContribNet,
                    'profit_net'                => $totalProfitNet,
                    'loan_issued_net'           => $totalLoanNet,
                    'loan_repayments'           => $totalRepayIn,
                    'penalties_charged'         => $totalPenaltiesCharged,
                    'penalty_payments'          => $totalPenaltyPayments,
                ],
                'repayment_breakdown' => [
                    'principal_repaid' => round($principalRepaid, 2),
                    'interest_paid'    => round($interestReceived, 2),
                ],
                'outstanding_loans_now' => [
                    'total'     => round($outstandingTotal, 2),
                    'principal' => round($outstandingPrincipal, 2),
                    'interest'  => round($outstandingInterest, 2),
                ],
            ],
            'timeline' => $timeline,
            'data' => [
                'opening_balances'      => $openingBalancesData,
                'contributions'         => $contributions,
                'loans'                 => $loans,
                'repayments'            => $repayments,
                'penalties'             => $penalties,
                'profit_distributions'  => $profitDistributions,
                'ledger_transactions'   => $transactions,
            ],
        ];
    }
}