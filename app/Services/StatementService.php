<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Loan;
use App\Models\LoanRepayment;
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
        // ✅ Authorization: admin/treasurer can view anyone; member can view self only
        if (!in_array($viewer->role, ['admin', 'treasurer'], true) && $viewer->id !== $member->id) {
            throw new \Exception('Forbidden');
        }

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt   = $to ? Carbon::parse($to)->endOfDay() : null;

        $applyDateTime = function ($query, string $column = 'created_at') use ($fromDt, $toDt) {
            if ($fromDt) $query->where($column, '>=', $fromDt);
            if ($toDt)   $query->where($column, '<=', $toDt);
            return $query;
        };

        /* =========================
           DATA SOURCES
           ========================= */

        // Contributions: filter by expected_date range (more natural for cycles)
        $contribQ = Contribution::where('user_id', $member->id);
        if ($from) $contribQ->whereDate('expected_date', '>=', $from);
        if ($to)   $contribQ->whereDate('expected_date', '<=', $to);

        $contributions = $contribQ->orderBy('expected_date')->get([
            'id', 'amount', 'expected_date', 'paid_date', 'status', 'penalty_amount', 'recorded_by', 'created_at'
        ]);

        // Loans: filter by created_at
        $loanQ = Loan::where('user_id', $member->id);
        $applyDateTime($loanQ);

        $loans = $loanQ->orderBy('created_at')->get([
            'id', 'principal', 'total_payable', 'due_date', 'status', 'created_at'
        ]);

        // Repayments: filter by created_at
        $repayQ = LoanRepayment::whereHas('loan', fn($q) => $q->where('user_id', $member->id));
        $applyDateTime($repayQ);

        $repayments = $repayQ->orderBy('created_at')->get([
            'id', 'loan_id', 'amount', 'principal_component', 'interest_component', 'repayment_date', 'recorded_by', 'created_at'
        ]);

        // Penalties: filter by created_at
        $penQ = Penalty::where('user_id', $member->id);
        $applyDateTime($penQ);

        $penalties = $penQ->orderBy('created_at')->get([
            'id', 'source_type', 'source_id', 'amount', 'reason', 'status', 'created_at'
        ]);

        // Profit distributions: filter by created_at
        $profitQ = ProfitDistribution::where('user_id', $member->id);
        $applyDateTime($profitQ);

        $profitDistributions = $profitQ->orderBy('created_at')->get([
            'id', 'profit_cycle_id', 'amount', 'distribution_type', 'status', 'created_at'
        ]);

        // Ledger transactions: filter by created_at
        $txQ = Transaction::where('user_id', $member->id);
        $applyDateTime($txQ);

        $transactions = $txQ->orderBy('created_at')->get([
            'id', 'type', 'debit', 'credit', 'reference', 'source_type', 'source_id', 'created_at'
        ]);

        /* =========================
           SUMMARY (ledger-driven)
           ========================= */

        $totalContrib      = (float) $transactions->where('type', 'contribution')->sum('credit');
        $totalProfitCredits= (float) $transactions->where('type', 'profit')->sum('credit');
        $totalLoanOut      = (float) $transactions->where('type', 'loan_disbursement')->sum('debit');
        $totalRepayIn      = (float) $transactions->where('type', 'loan_repayment')->sum('credit');
        $totalPenalties    = (float) $transactions->where('type', 'penalty')->sum('credit');

        $interestReceived  = (float) $repayments->sum('interest_component');
        $principalRepaid   = (float) $repayments->sum('principal_component');

        // Outstanding NOW ignores date filter
        $allLoansNow = Loan::where('user_id', $member->id)->get();
        $outstandingTotal     = (float) $allLoansNow->sum(fn($l) => $l->outstandingBalance());
        $outstandingPrincipal = (float) $allLoansNow->sum(fn($l) => $l->outstandingPrincipal());
        $outstandingInterest  = (float) $allLoansNow->sum(fn($l) => $l->outstandingInterest());

        /* =========================
           TIMELINE
           ========================= */

        $repaymentMap = $repayments->keyBy('id');

        $timeline = $transactions->map(function ($tx) use ($repaymentMap) {

            $direction = ((float)$tx->credit) > 0 ? 'in' : 'out';
            $amount    = ((float)$tx->credit) > 0 ? (float)$tx->credit : (float)$tx->debit;

            $title = match ($tx->type) {
                'contribution'      => 'Contribution',
                'opening_balance'   => 'Opening Balance', // ✅ add friendly title
                'loan_disbursement' => 'Loan Disbursement',
                'loan_repayment'    => 'Loan Repayment',
                'penalty'           => 'Penalty Payment',
                'profit'            => ((float)$tx->credit) > 0 ? 'Profit Added to Savings' : 'Profit Payout',
                default             => 'Transaction',
            };

            $item = [
                'date'      => $tx->created_at->toDateTimeString(),
                'category'  => $tx->type,
                'direction' => $direction,
                'amount'    => round($amount, 2),
                'debit'     => round((float)$tx->debit, 2),
                'credit'    => round((float)$tx->credit, 2),
                'title'     => $title,
                'reference' => $tx->reference,
                'meta'      => [],
            ];

            if ($tx->type === 'loan_repayment' && $tx->source_type === 'loan_repayment' && $tx->source_id) {
                $rep = $repaymentMap->get((int)$tx->source_id);
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
           NEXT DUE (Financial year)
           ========================= */

        $todayPeriod = Carbon::now('Africa/Kigali')->format('Y-m');
        $commitmentToday = $this->commitmentService->activeForPeriod($member->id, $todayPeriod);

        $nextDue = null;
        if ($commitmentToday) {
            $nextDue = $this->dueDateService->computeNextDueForMember(
                memberId: $member->id,
                commitmentAmount: (float) $commitmentToday->amount
            );
        } else {
            // still return FY info so UI can show "Set commitment"
            $fy = $this->dueDateService->getActiveYear();
            $nextDue = [
                'financial_year' => $fy->year_key,
                'due_day' => $fy->due_day,
                'grace_days' => $fy->grace_days,
                'next_due_period' => $todayPeriod,
                'next_due_date' => null,
                'days_remaining' => null,
                'is_overdue' => false,
                'hint' => 'no_commitment',
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
            'next_due' => $nextDue, // ✅ NEW
            'summary' => [
                'totals_from_ledger' => [
                    'contributions'     => round($totalContrib, 2),
                    'profit_credits'    => round($totalProfitCredits, 2),
                    'loan_disbursed'    => round($totalLoanOut, 2),
                    'loan_repayments'   => round($totalRepayIn, 2),
                    'penalties_charged' => round($totalPenalties, 2),
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
                'contributions'        => $contributions,
                'loans'                => $loans,
                'repayments'           => $repayments,
                'penalties'            => $penalties,
                'profit_distributions' => $profitDistributions,
                'ledger_transactions'  => $transactions,
            ],
        ];
    }
}
