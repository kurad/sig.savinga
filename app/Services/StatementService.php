<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

class StatementService
{
    public function __construct(
        protected DueDateService $dueDateService,
        protected CommitmentService $commitmentService
    ) {}

    public function memberStatement(
        User $viewer,
        User $member,
        ?string $from = null,
        ?string $to = null
    ): array {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true) && $viewer->id !== $member->id) {
            throw new \Exception('Forbidden');
        }

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt   = $to ? Carbon::parse($to)->endOfDay() : null;

        $transactionsQuery = Transaction::query()
            ->where('user_id', $member->id);

        if ($fromDt) {
            $transactionsQuery->where('created_at', '>=', $fromDt);
        }

        if ($toDt) {
            $transactionsQuery->where('created_at', '<=', $toDt);
        }

        $transactions = $transactionsQuery
            ->orderBy('created_at')
            ->get([
                'id',
                'user_id',
                'beneficiary_id',
                'type',
                'debit',
                'credit',
                'reference',
                'source_type',
                'source_id',
                'created_at',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Member Funds
        |--------------------------------------------------------------------------
        | This represents what belongs to the member:
        | opening balance + contributions + profit - reversals/adjustments.
        */
        $openingNet = round(
            (float) $transactions
                ->whereIn('type', ['opening_balance', 'opening_balance_adjustment'])
                ->sum('credit')
            -
            (float) $transactions
                ->whereIn('type', ['opening_balance', 'opening_balance_adjustment'])
                ->sum('debit'),
            2
        );

        $contributionsNet = round(
            (float) $transactions
                ->whereIn('type', ['contribution', 'contribution_adjustment'])
                ->sum('credit')
            -
            (float) $transactions
                ->whereIn('type', ['contribution_reversal'])
                ->sum('debit'),
            2
        );

        $profitNet = round(
            (float) $transactions
                ->where('type', 'profit')
                ->sum('credit')
            -
            (float) $transactions
                ->where('type', 'profit')
                ->sum('debit'),
            2
        );

        $memberFunds = round($openingNet + $contributionsNet + $profitNet, 2);

        /*
        |--------------------------------------------------------------------------
        | Cash Movements Net
        |--------------------------------------------------------------------------
        | This is not group cash on hand. It is only the member-related cash movement
        | in the selected period.
        */
        $cashIn = round(
            (float) $transactions
                ->whereIn('type', [
                    'income',
                    'loan_repayment',
                    'penalty_paid',
                    'registration_fee',
                    'donation',
                    'fine',
                ])
                ->sum('credit'),
            2
        );

        $cashOut = round(
            (float) $transactions
                ->whereIn('type', [
                    'expense',
                    'loan_disbursement',
                    'investment',
                    'withdrawal',
                    'member_withdrawal',
                ])
                ->sum('debit'),
            2
        );

        $cashMovementsNet = round($cashIn - $cashOut, 2);

        /*
        |--------------------------------------------------------------------------
        | Book Balance
        |--------------------------------------------------------------------------
        | Full ledger net for this member in the selected range.
        */
        $bookBalance = round(
            (float) $transactions->sum('credit') - (float) $transactions->sum('debit'),
            2
        );

        /*
        |--------------------------------------------------------------------------
        | Loans
        |--------------------------------------------------------------------------
        | Current outstanding loans are calculated from all loans, not only date-filtered
        | transactions, because this shows the member's current loan position.
        */
        $loans = Loan::where('user_id', $member->id)->get();

        $outstandingTotal = round(
            (float) $loans->sum(fn ($loan) => $loan->outstandingBalance()),
            2
        );

        $outstandingPrincipal = round(
            (float) $loans->sum(fn ($loan) => $loan->outstandingPrincipal()),
            2
        );

        $outstandingInterest = round(
            (float) $loans->sum(fn ($loan) => $loan->outstandingInterest()),
            2
        );

        /*
        |--------------------------------------------------------------------------
        | Penalties
        |--------------------------------------------------------------------------
        */
        $penaltiesCharged = round(
            (float) $transactions->where('type', 'penalty')->sum('credit'),
            2
        );

        $penaltiesPaid = round(
            (float) $transactions->where('type', 'penalty_paid')->sum('credit')
            -
            (float) $transactions->where('type', 'penalty_paid')->sum('debit'),
            2
        );

        /*
        |--------------------------------------------------------------------------
        | Timeline
        |--------------------------------------------------------------------------
        */
        $timeline = $transactions->map(function ($tx) {
            $credit = round((float) $tx->credit, 2);
            $debit = round((float) $tx->debit, 2);

            return [
                'id' => $tx->id,
                'date' => optional($tx->created_at)->toDateTimeString(),
                'type' => $tx->type,
                'title' => $this->transactionTitle($tx->type),
                'direction' => $credit > 0 ? 'in' : 'out',
                'amount' => $credit > 0 ? $credit : $debit,
                'credit' => $credit,
                'debit' => $debit,
                'reference' => $tx->reference,
                'source_type' => $tx->source_type,
                'source_id' => $tx->source_id,
            ];
        })->values();

        /*
        |--------------------------------------------------------------------------
        | Next Due
        |--------------------------------------------------------------------------
        */
        $todayPeriod = Carbon::now('Africa/Kigali')->format('Y-m');

        $commitmentToday = $this->commitmentService->activeForPeriod(
            $member->id,
            null,
            $todayPeriod
        );

        if ($commitmentToday) {
            $nextDue = $this->dueDateService->computeNextDueForMember(
                memberId: $member->id,
                commitmentAmount: (float) $commitmentToday->amount
            );
        } else {
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
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
            ],

            'filters' => [
                'from' => $from,
                'to' => $to,
            ],

            'next_due' => $nextDue,

            'summary' => [
                'member_funds' => $memberFunds,
                'book_balance' => $bookBalance,
                'cash_movements_net' => $cashMovementsNet,

                'breakdown' => [
                    'opening_balance_net' => $openingNet,
                    'contributions_net' => $contributionsNet,
                    'profit_net' => $profitNet,
                    'cash_in' => $cashIn,
                    'cash_out' => $cashOut,
                ],

                'loans' => [
                    'outstanding_total' => $outstandingTotal,
                    'outstanding_principal' => $outstandingPrincipal,
                    'outstanding_interest' => $outstandingInterest,
                ],

                'penalties' => [
                    'charged' => $penaltiesCharged,
                    'paid' => $penaltiesPaid,
                    'unpaid' => round($penaltiesCharged - $penaltiesPaid, 2),
                ],
            ],

            'timeline' => $timeline,
        ];
    }

    protected function transactionTitle(?string $type): string
    {
        return match ($type) {
            'opening_balance' => 'Opening Balance',
            'opening_balance_adjustment' => 'Opening Balance Adjustment',
            'contribution' => 'Contribution',
            'contribution_adjustment' => 'Contribution Adjustment',
            'contribution_reversal' => 'Contribution Reversal',
            'loan_disbursement' => 'Loan Disbursement',
            'loan_repayment' => 'Loan Repayment',
            'penalty' => 'Penalty Charged',
            'penalty_paid' => 'Penalty Payment',
            'penalty_waived' => 'Penalty Waived',
            'profit' => 'Profit',
            'income' => 'Income',
            'expense' => 'Expense',
            'registration_fee' => 'Registration Fee',
            'donation' => 'Donation',
            'fine' => 'Fine',
            'investment' => 'Investment',
            'investment_sale' => 'Investment Sale',
            default => 'Transaction',
        };
    }
}