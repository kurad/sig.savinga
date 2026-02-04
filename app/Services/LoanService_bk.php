<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Loan;
use App\Models\User;
use App\Models\SystemRule;
use App\Models\LoanRepayment;
use App\Models\LoanInstallment;
use Illuminate\Support\Facades\DB;

class LoanService
{
    public function __construct(
        protected TransactionService $ledger,
        protected PenaltyService $penaltyService,
        protected ContributionService $contributionService
    ) {}

    public function listLoans(?int $userId = null, ?string $status = null, int $perPage = 15)
    {
        $p = Loan::query()
            ->with(['user:id,name,email,phone'])
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        // ✅ Append computed fields per row (UI-friendly)
        $p->getCollection()->transform(function (Loan $loan) {

            $principal = round((float) $loan->principal, 2);
            $interest = round((float) $loan->interest, 2);
            $totalPayable = round((float) $loan->total_payable, 2);

            // Outstanding (live computed)
            $outstanding = [
                'total'     => round((float) $loan->outstandingBalance(), 2),
                'principal' => round((float) $loan->outstandingPrincipal(), 2),
                'interest'  => round((float) $loan->outstandingInterest(), 2),
            ];

            $mode = (string) ($loan->repayment_mode ?? 'once');

            $installmentsMeta = null;

            if ($mode === 'installment') {
                [$paidCount, $totalCount, $next] = $this->installmentStatsForLoan($loan->id);

                $installmentsMeta = [
                    'paid'  => $paidCount,
                    'total' => $totalCount,
                    'next'  => $next, // null if fully paid or no schedule
                ];
            }

            // Attach computed fields
            $loan->interest = $interest;
            $loan->outstanding = $outstanding;
            $loan->installments = $installmentsMeta;

            // Nice date strings (optional, but helps Vue)
            if (!empty($loan->issued_date)) {
                $loan->issued_date = Carbon::parse($loan->issued_date)->toDateString();
            }
            if (!empty($loan->due_date)) {
                $loan->due_date = Carbon::parse($loan->due_date)->toDateString();
            }
            $loan->terms = [
                'interest_rate' => round((float) $loan->interest_rate, 2),
                'interest_basis' => (string) ($loan->interest_basis ?? 'per_year'),
                'interest_term_months' => $loan->interest_term_months ? (int) $loan->interest_term_months : null,
                'interest_amount' => round((float) ($loan->interest_amount ?? $interest), 2),
                'principal' => $principal,
                'total_payable' => $totalPayable,
            ];

            return $loan;
        });

        return $p;
    }
    /**
     * Returns [paidCount, totalCount, nextInstallment]
     */
    protected function installmentStatsForLoan(int $loanId): array
    {
        $totalCount = (int) LoanInstallment::where('loan_id', $loanId)->count();

        $paidCount = (int) LoanInstallment::where('loan_id', $loanId)
            ->where('status', 'paid')
            ->count();

        $next = LoanInstallment::where('loan_id', $loanId)
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('installment_no')
            ->first();

        $nextInstallment = null;
        if ($next) {
            $due  = round((float) ($next->amount_due ?? 0), 2);
            $paid = round((float) ($next->paid_amount ?? 0), 2);
            $remaining = round(max(0, $due - $paid), 2);

            $nextInstallment = [
                'id'             => $next->id,
                'installment_no' => (int) $next->installment_no,
                'due_date'       => $next->due_date ? Carbon::parse($next->due_date)->toDateString() : null,
                'amount_due'     => $due,
                'paid_amount'    => $paid,
                'remaining'      => $remaining,
                'status'         => (string) $next->status,
            ];
        }

        return [$paidCount, $totalCount, $nextInstallment];
    }



    /**
     * Disburse a loan
     * ✅ Interest rate is provided by UI (NOT from SystemRule).
     *
     * @param float  $interestRate  Required (UI-provided). Example: 10 for 10%
     * @param string $interestBasis per_month | per_year | per_term
     * @param int|null $interestTermMonths Required when basis = per_term (e.g. 3 for a term)
     * @param string|null $rateNotes Optional notes shown in audit
     */
    public function disburse(
        int $memberId,
        float $principal,
        string $dueDate,
        int $recordedBy,
        ?string $repaymentMode = null,     // 'once' | 'installment'
        ?int $durationMonths = null,       // used for installment
        array $guarantors = [],             // [['user_id'=>X,'amount'=>Y], ...]
        ?float $interestRate = 0,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $rateNotes = null
    ): Loan {
        return DB::transaction(function () use (
            $memberId,
            $principal,
            $dueDate,
            $recordedBy,
            $repaymentMode,
            $durationMonths,
            $guarantors,
            $interestRate,
            $interestBasis,
            $interestTermMonths,
            $rateNotes
        ) {
            $tz = 'Africa/Kigali';
            $rules = SystemRule::firstOrFail();

            $principal = round(max(0, (float)$principal), 2);
            if ($principal <= 0) {
                throw new \Exception('Principal must be greater than 0.');
            }
            $interestRate = round((float)$interestRate, 2);
            if ($interestRate <= 0) {
                throw new \Exception('Interest rate is required and must be greater than 0.');
            }
            $interestBasis = strtolower(trim($interestBasis));
            if (!in_array($interestBasis, ['per_month', 'per_year', 'per_term'], true)) {
                throw new \Exception('Invalid interest basis. Use per_month, per_year, or per_term.');
            }
            if ($interestBasis === 'per_term') {
                $interestTermMonths = $interestTermMonths ? (int)$interestTermMonths : 0;
                if ($interestTermMonths <= 0) {
                    throw new \Exception('interest_term_months is required when interest_basis is per_term.');
                }
            } else {
                // ignore term months for non-term basis
                $interestTermMonths = null;
            }

            // 1) Active loan rule
            if (!(bool)$rules->allow_multiple_active_loans) {
                $hasActive = Loan::where('user_id', $memberId)->where('status', 'active')->exists();
                if ($hasActive) {
                    throw new \Exception('Member already has an active loan.');
                }
            }

            // 2) Contribution months rule
            $minMonths = (int)($rules->min_contribution_months ?? 0);
            if ($minMonths > 0) {
                $months = (int)$this->contributionService->contributedMonthsCount($memberId);
                if ($months < $minMonths) {
                    throw new \Exception("Member must have at least {$minMonths} contribution months.");
                }
            }

            // 3) Max loan by contributions (INCLUDING opening balance)
            $savingBase = (float)$this->contributionService->savingsBaseForLoanLimit($memberId);

            $maxAllowed = match ($rules->loan_limit_type) {
                'multiple' => $savingBase * (float)($rules->loan_limit_value ?? 3),
                'equal'    => $savingBase,
                'fixed'    => (float)($rules->loan_limit_value ?? 0),
                default    => $savingBase * 3,
            };
            $maxAllowed = round(max(0, $maxAllowed), 2);

            $shortfall = round(max(0, $principal - $maxAllowed), 2);

            // 4) Validate guarantors if shortfall exists
            if ($shortfall > 0) {
                if (!is_array($guarantors) || count($guarantors) === 0) {
                    throw new \Exception('Loan exceeds allowed limit. Guarantor pledge required.');
                }

                $pledged = 0.0;
                $seen = [];

                foreach ($guarantors as $g) {
                    $gid = (int)($g['user_id'] ?? 0);
                    $amt = round((float)($g['amount'] ?? 0), 2);

                    if ($gid <= 0 || $amt <= 0) continue;

                    if ($gid === $memberId) {
                        throw new \Exception('Borrower cannot be a guarantor.');
                    }

                    // avoid duplicates
                    if (isset($seen[$gid])) continue;
                    $seen[$gid] = true;

                    // ensure guarantor exists
                    $exists = User::whereKey($gid)->exists();
                    if (!$exists) {
                        throw new \Exception("Guarantor user #{$gid} not found.");
                    }

                    $pledged += $amt;
                }

                $pledged = round($pledged, 2);
                if ($pledged < $shortfall) {
                    throw new \Exception(
                        'Not enough guarantor pledge. Need at least ' . number_format($shortfall) . ' RWF'
                    );
                }
            }

            // Repayment mode + duration
            $mode = $repaymentMode ?: ($rules->loan_default_repayment_mode ?? 'once');
            $mode = in_array($mode, ['once', 'installment'], true) ? $mode : 'once';

            $duration = $durationMonths ?? (int)($rules->loan_duration_months ?? 1);
            $duration = max(1, (int)$duration);

            // ✅ compute interest using UI-provided terms, then snapshot to loan
            $interestAmount = $this->computeInterest(
                principal: $principal,
                rate: $interestRate,
                basis: $interestBasis,
                durationMonths: $duration,
                termMonths: $interestTermMonths
            );

            $totalPayable = round($principal + $interestAmount, 2);

            $monthlyInstallment = null;
            if ($mode === 'installment') {
                $monthlyInstallment = round($totalPayable / $duration, 2);
            }

            $issuedDate = Carbon::now($tz)->toDateString();

            $computedDueDate = $mode === 'installment'
                ? Carbon::parse($issuedDate, $tz)->addMonthsNoOverflow($duration)->toDateString()
                : Carbon::parse($dueDate, $tz)->toDateString();

            // Create Loan
            $loan = Loan::create([
                'user_id'             => $memberId,
                'principal'           => $principal,
                'interest_rate'       => $interestRate,
                'interest_basis'       => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest_amount'      => $interestAmount,
                'total_payable'       => $totalPayable,
                'duration_months'     => $duration,
                'issued_date'         => $issuedDate,
                'due_date'            => $computedDueDate,
                'status'              => 'active',
                'repayment_mode'      => $mode,
                'monthly_installment' => $monthlyInstallment,
                'approved_by'         => $recordedBy,

                // audit who set the rate
                'rate_set_by'          => $recordedBy,
                'rate_set_at'          => now($tz),
                'rate_notes'           => $rateNotes,
            ]);

            // Create Installment Schedule if installment mode
            if ($mode === 'installment') {
                $base = round($totalPayable / $duration, 2);

                // Make last installment adjust rounding drift cleanly
                $sumFirst = round($base * ($duration - 1), 2);
                $lastAmt  = round($totalPayable - $sumFirst, 2);


                for ($i = 1; $i <= $duration; $i++) {

                    $instDue = Carbon::parse($issuedDate, $tz)
                        ->addMonthsNoOverflow($i)
                        ->toDateString();
                    $amt = ($i === $duration) ? $lastAmt : $base;

                    \App\Models\LoanInstallment::create([
                        'loan_id' => $loan->id,
                        'installment_no' => $i,
                        'due_date' => $instDue,
                        'amount_due' => $amt,
                        'status' => 'unpaid',
                        'paid_amount' => 0,
                    ]);
                }
            }
            // Save guarantors only if shortfall > 0
            if ($shortfall > 0) {
                $seen = [];
                foreach ($guarantors as $g) {
                    $gid = (int)($g['user_id'] ?? 0);
                    $amt = round((float)($g['amount'] ?? 0), 2);

                    if ($gid <= 0 || $amt <= 0) continue;
                    if ($gid === $memberId) continue;
                    if (isset($seen[$gid])) continue;
                    $seen[$gid] = true;

                    \App\Models\LoanGuarantor::create([
                        'loan_id'            => $loan->id,
                        'guarantor_user_id'  => $gid,
                        'pledged_amount'     => $amt,
                        'status'             => 'active',
                    ]);
                }
            }

            // Ledger OUT (loan disbursement)
            $this->ledger->record(
                type: 'loan_disbursement',
                debit: $principal,
                credit: 0,
                userId: $memberId,
                reference: 'Loan ID ' . $loan->id,
                createdBy: $recordedBy,
                sourceType: 'loan',
                sourceId: $loan->id
            );

            return $loan;
        });
    }

    /**
     * Repay a loan (no overpayment here; use repayWithAutoSplit if you want that behavior)
     */
    public function repay(
        int $loanId,
        float $amount,
        string $paidDate,
        
        int $recordedBy
    ): LoanRepayment {
        return DB::transaction(function () use ($loanId, $amount, $paidDate, $recordedBy) {
            $tz = 'Africa/Kigali';

            $loan = Loan::lockForUpdate()->findOrFail($loanId);
            $paid = Carbon::parse($paidDate);

            if ($loan->status !== 'active') {
                throw new \Exception('Only active loans can be repaid.');
            }

            $amount = round((float)$amount, 2);
            if ($amount <= 0) {
                throw new \Exception('Repayment amount must be greater than 0.');
            }

            $outstanding = (float) $loan->outstandingBalance();

            // Keep repay() strict
            if ($amount > $outstanding) {
                throw new \Exception('Repayment exceeds outstanding loan balance.');
            }
            $mode = (string) ($loan->repayment_mode ?? 'once');

            if ($mode === 'once') {
                $due = $loan->due_date ? Carbon::parse($loan->due_date, $tz) : null;
                if ($due && $paid->gt($due)) {
                    $this->penaltyService->loanLate(
                        memberId: $loan->user_id,
                        loanId: $loan->id,
                        recordedBy: $recordedBy
                    );
                }
            }

            if ($mode === 'installment') {
                $totalPayable  = round((float) $loan->total_payable, 2);
                $totalInterest = round((float) $loan->interest, 2);

                $ratio = $totalPayable > 0 ? ($totalInterest / $totalPayable) : 0.0;

                $interestComponent  = round($amount * $ratio, 2);
                $principalComponent = round($amount - $interestComponent, 2);
            } else {
                [$interestComponent, $principalComponent] = $this->allocateRepaymentInterestFirst($loan, $amount);
            }

            $repayment = LoanRepayment::create([
                'loan_id'             => $loan->id,
                'amount'              => $amount,
                'interest_component'  => $interestComponent,
                'principal_component' => $principalComponent,
                'repayment_date'      => $paid,
                'recorded_by'         => $recordedBy,
            ]);

            $this->allocateToInstallments($loan, $amount, $paid, $recordedBy);

            // Ledger IN (repayment)
            $this->ledger->record(
                type: 'loan_repayment',
                debit: 0,
                credit: $amount,
                userId: $loan->user_id,
                reference: 'Loan repayment ID ' . $repayment->id . ' for Loan ID ' . $loan->id,
                createdBy: $recordedBy,
                sourceType: 'loan_repayment',
                sourceId: $repayment->id
            );

            // Check completion
            $loan->refresh();
            if (round((float)$loan->outstandingBalance(), 2) <= 0) {
                $loan->update(['status' => 'completed']);

                \App\Models\LoanGuarantor::where('loan_id', $loan->id)
                    ->where('status', 'active')
                    ->update(['status' => 'released']);
            }

            return $repayment;
        });
    }

    /**
     * Repay a loan, auto-splitting overpayment into contribution.
     */
    public function repayWithAutoSplit(
        int $loanId,
        float $amount,
        string $paidDate,
        string $period,
        int $recordedBy
    ): array {
        return DB::transaction(function () use ($loanId, $amount, $paidDate,  $period, $recordedBy) {

            $loan = Loan::lockForUpdate()->findOrFail($loanId);

            if ($loan->status !== 'active') {
                throw new \Exception('Only active loans can be repaid.');
            }

            $paidDateYmd = Carbon::parse($paidDate)->toDateString();
            $amount = round((float)$amount, 2);

            if ($amount <= 0) {
                throw new \Exception('Repayment amount must be greater than 0.');
            }

            $outstanding = (float) $loan->outstandingBalance();

            // If already settled => everything becomes contribution
            if ($outstanding <= 0) {
                $contrib = $this->contributionService->record(
                    memberId: $loan->user_id,
                    amount: $amount,
                    expectedDate: $paidDateYmd,
                    paidDate: $paidDateYmd,
                    recordedBy: $recordedBy
                );

                return [
                    'message' => 'Loan is already settled. Amount recorded as contribution.',
                    'loan' => [
                        'id' => $loan->id,
                        'status' => $loan->status,
                        'outstanding_balance' => round((float)$loan->outstandingBalance(), 2),
                        'outstanding_principal' => round((float)$loan->outstandingPrincipal(), 2),
                        'outstanding_interest' => round((float)$loan->outstandingInterest(), 2),
                    ],
                    'repayment' => null,
                    'extra_contribution' => $contrib,
                ];
            }

            $repayAmount = round(min($amount, $outstanding), 2);
            $extraAmount = round(max(0, $amount - $outstanding), 2);

            $repayment = $this->repay(
                loanId: $loan->id,
                amount: $repayAmount,
                paidDate: $paidDateYmd,
                recordedBy: $recordedBy
            );

            $loan->refresh();

            $extraContribution = null;
            if ($extraAmount > 0) {
                $extraContribution = $this->contributionService->record(
                    memberId: $loan->user_id,
                    amount: $extraAmount,
                    expectedDate: $paidDateYmd,
                    paidDate: $paidDateYmd,
                    recordedBy: $recordedBy
                );
            }

            return [
                'message' => $extraAmount > 0
                    ? 'Repayment recorded and extra amount saved as contribution.'
                    : 'Repayment recorded successfully.',
                'loan' => [
                    'id' => $loan->id,
                    'status' => $loan->status,
                    'outstanding_balance' => round((float)$loan->outstandingBalance(), 2),
                    'outstanding_principal' => round((float)$loan->outstandingPrincipal(), 2),
                    'outstanding_interest' => round((float)$loan->outstandingInterest(), 2),
                ],
                'repayment' => [
                    'id' => $repayment->id,
                    'amount' => (float) $repayment->amount,
                    'principal_component' => (float) $repayment->principal_component,
                    'interest_component' => (float) $repayment->interest_component,
                    'paid_date' => $repayment->repayment_date
                        ? $repayment->repayment_date->toDateString()
                        : $paidDateYmd,
                ],
                'extra_contribution' => $extraContribution,
            ];
        });
    }

    /**
     * Interest-first allocation
     * Returns: [interestComponent, principalComponent]
     */
    protected function allocateRepaymentInterestFirst(Loan $loan, float $amount): array
    {
        $interestOutstanding = (float) $loan->outstandingInterest();
        $principalOutstanding = (float) $loan->outstandingPrincipal();

        $interestComponent = min($amount, $interestOutstanding);
        $remaining = $amount - $interestComponent;

        $principalComponent = min($remaining, $principalOutstanding);

        return [round($interestComponent, 2), round($principalComponent, 2)];
    }

    /**
     * Member loan summary (self or chair/treasurer)
     */
    public function memberLoanSummary(User $viewer, User $member, ?string $from = null, ?string $to = null): array
    {
        if (!in_array($viewer->role, ['chair', 'treasurer'], true) && $viewer->id !== $member->id) {
            throw new \Exception('Forbidden');
        }

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt   = $to ? Carbon::parse($to)->endOfDay() : null;

        $loanQ = Loan::where('user_id', $member->id);
        if ($fromDt) $loanQ->where('created_at', '>=', $fromDt);
        if ($toDt)   $loanQ->where('created_at', '<=', $toDt);

        $loans = $loanQ->get([
            'id',
            'principal',
            'interest_rate',
            'total_payable',
            'due_date',
            'status',
            'created_at',
        ]);

        $loanIds = $loans->pluck('id');

        $repayments = $loanIds->isEmpty()
            ? collect()
            : LoanRepayment::whereIn('loan_id', $loanIds)
            ->when($fromDt, fn($q) => $q->where('created_at', '>=', $fromDt))
            ->when($toDt, fn($q) => $q->where('created_at', '<=', $toDt))
            ->get(['loan_id', 'amount', 'principal_component', 'interest_component', 'created_at']);

        $totalBorrowedPrincipal = (float) $loans->sum('principal');

        $totalInterestCharged = (float) $loans->sum(function ($l) {
            return (float) $l->total_payable - (float) $l->principal;
        });

        $totalRepaidAmount = (float) $repayments->sum('amount');
        $principalRepaid   = (float) $repayments->sum('principal_component');
        $interestPaid      = (float) $repayments->sum('interest_component');

        // Outstanding NOW (ignore date filters)
        $allLoansNow = Loan::where('user_id', $member->id)->get();

        $outstandingTotal = (float) $allLoansNow->sum(fn($l) => (float) $l->outstandingBalance());
        $outstandingPrincipal = (float) $allLoansNow->sum(fn($l) => (float) $l->outstandingPrincipal());
        $outstandingInterest  = (float) $allLoansNow->sum(fn($l) => (float) $l->outstandingInterest());

        $activeCount = (int) $allLoansNow->where('status', 'active')->count();
        $completedCount = (int) $allLoansNow->where('status', 'completed')->count();

        $today = Carbon::today();
        $activeLoans = $allLoansNow->where('status', 'active');

        $overdueCount = (int) $activeLoans->filter(function ($loan) use ($today) {
            $due = $loan->due_date ? Carbon::parse($loan->due_date) : null;
            return $due && $due->lt($today) && (float)$loan->outstandingBalance() > 0;
        })->count();

        $nextDue = $activeLoans
            ->filter(fn($l) => !empty($l->due_date))
            ->sortBy(fn($l) => Carbon::parse($l->due_date)->timestamp)
            ->first();

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
            'summary' => [
                'totals_in_range' => [
                    'principal_borrowed' => round($totalBorrowedPrincipal, 2),
                    'interest_charged'   => round($totalInterestCharged, 2),
                    'total_repaid'       => round($totalRepaidAmount, 2),
                    'principal_repaid'   => round($principalRepaid, 2),
                    'interest_paid'      => round($interestPaid, 2),
                ],
                'outstanding_now' => [
                    'total'     => round($outstandingTotal, 2),
                    'principal' => round($outstandingPrincipal, 2),
                    'interest'  => round($outstandingInterest, 2),
                ],
                'counts_now' => [
                    'active_loans'    => $activeCount,
                    'completed_loans' => $completedCount,
                    'overdue_loans'   => $overdueCount,
                ],
                'next_due_loan' => $nextDue ? [
                    'loan_id' => $nextDue->id,
                    'due_date' => Carbon::parse($nextDue->due_date)->toDateString(),
                    'outstanding_balance' => round((float)$nextDue->outstandingBalance(), 2),
                ] : null,
            ],
        ];
    }

    /**
     * Top-up preview (admin/chair/treasurer): used by UI modal
     */
    public function topUpPreview(int $baseLoanId, User $viewer): array
    {
        if (!in_array($viewer->role, ['chair', 'admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $rules = SystemRule::firstOrFail();
        $loan = Loan::with('repayments')->findOrFail($baseLoanId);

        $minInst = (int)($rules->min_installments_before_top_up ?? 3);

        $paidInst = method_exists($loan, 'installmentsPaidCount')
            ? (int) $loan->installmentsPaidCount()
            : 0;

        // IMPORTANT: use savings base (includes opening balance)
        $savingBase = (float) $this->contributionService->savingsBaseForLoanLimit($loan->user_id);

        $maxAllowed = match ($rules->loan_limit_type) {
            'multiple' => $savingBase * (float)($rules->loan_limit_value ?? 3),
            'equal'    => $savingBase,
            'fixed'    => (float)($rules->loan_limit_value ?? 0),
            default    => $savingBase * 3,
        };

        // Exposure: all active loans outstanding
        $activeLoans = Loan::where('user_id', $loan->user_id)
            ->where('status', 'active')
            ->get();

        $exposureNow = (float) $activeLoans->sum(fn($l) => (float) $l->outstandingBalance());
        $headroom = max(0, (float)$maxAllowed - (float)$exposureNow);

        $enabled = (bool)($rules->allow_loan_top_up ?? false);

        $allowed = true;
        $reason = null;

        if (!$enabled) {
            $allowed = false;
            $reason = 'Top-up is disabled in system rules.';
        } elseif ($loan->status !== 'active') {
            $allowed = false;
            $reason = 'Only active loans can be topped up.';
        } elseif (($loan->repayment_mode ?? 'once') !== 'installment') {
            $allowed = false;
            $reason = 'Top-up is only allowed for installment loans.';
        } elseif ($paidInst < $minInst) {
            $allowed = false;
            $reason = "Top-up allowed after {$minInst} installments. Paid so far: {$paidInst}.";
        } elseif ($headroom <= 0) {
            $allowed = false;
            $reason = 'No headroom available based on savings base and active loan exposure.';
        }

        return [
            'loan' => [
                'id' => $loan->id,
                'user_id' => $loan->user_id,
                'status' => $loan->status,
                'repayment_mode' => $loan->repayment_mode,
                'monthly_installment' => (float) ($loan->monthly_installment ?? 0),
                'installments_paid' => $paidInst,
                'outstanding_balance' => round((float)$loan->outstandingBalance(), 2),
                'due_date' => $loan->due_date ? Carbon::parse($loan->due_date)->toDateString() : null,
            ],
            'rules' => [
                'allow_loan_top_up' => $enabled,
                'min_installments_before_top_up' => $minInst,
                'loan_limit_type' => (string)($rules->loan_limit_type ?? ''),
                'loan_limit_value' => (float)($rules->loan_limit_value ?? 0),
            ],
            'eligibility' => [
                'savings_base' => round($savingBase, 2),
                'max_allowed' => round((float)$maxAllowed, 2),
                'active_loan_exposure' => round((float)$exposureNow, 2),
                'headroom' => round((float)$headroom, 2),
            ],
            'allowed' => $allowed,
            'reason' => $reason,
        ];
    }

    /**
     * Top-up as NEW loan (creates a new disbursement) - based on headroom.
     */
    public function topUpAsNewLoan(
        int $baseLoanId,
        float $topUpAmount,
        string $dueDate,
        int $recordedBy,
        float $interestRate,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $rateNotes = null
    ): Loan {
        return DB::transaction(function () use (
            $baseLoanId,
            $topUpAmount,
            $dueDate,
            $recordedBy,
            $interestRate,
            $interestBasis,
            $interestTermMonths,
            $rateNotes
        ) {

            $tz = 'Africa/Kigali';
            $rules = SystemRule::firstOrFail();

            if (!(bool)($rules->allow_loan_top_up ?? false)) {
                throw new \Exception('Loan top-up is not enabled in system rules.');
            }

            $topUpAmount = round((float)$topUpAmount, 2);
            if ($topUpAmount <= 0) {
                throw new \Exception('Top-up amount must be greater than 0.');
            }
            $interestRate = round((float)$interestRate, 2);
            if ($interestRate <= 0) {
                throw new \Exception('Interest rate is required and must be greater than 0.');
            }
            $interestBasis = strtolower(trim($interestBasis));
            if (!in_array($interestBasis, ['per_month', 'per_year', 'per_term'], true)) {
                throw new \Exception('Invalid interest basis. Use per_month, per_year, or per_term.');
            }
            if ($interestBasis === 'per_term') {
                $interestTermMonths = $interestTermMonths ? (int)$interestTermMonths : 0;
                if ($interestTermMonths <= 0) {
                    throw new \Exception('interest_term_months is required when interest_basis is per_term.');
                }
            } else {
                $interestTermMonths = null;
            }

            $baseLoan = Loan::lockForUpdate()->findOrFail($baseLoanId);

            if ($baseLoan->status !== 'active') {
                throw new \Exception('Only active loans can be topped up.');
            }

            if (($baseLoan->repayment_mode ?? 'once') !== 'installment') {
                throw new \Exception('Top-up is only allowed for installment loans.');
            }

            $minInst = (int)($rules->min_installments_before_top_up ?? 3);
            $paidInst = method_exists($baseLoan, 'installmentsPaidCount')
                ? (int) $baseLoan->installmentsPaidCount()
                : 0;

            if ($paidInst < $minInst) {
                throw new \Exception("Top-up allowed after {$minInst} installments. Paid so far: {$paidInst}.");
            }

            // IMPORTANT: use savings base (includes opening balance)
            $savingBase = (float) $this->contributionService->savingsBaseForLoanLimit($baseLoan->user_id);

            $maxAllowed = match ($rules->loan_limit_type) {
                'multiple' => $savingBase * (float)($rules->loan_limit_value ?? 3),
                'equal'    => $savingBase,
                'fixed'    => (float)($rules->loan_limit_value ?? 0),
                default    => $savingBase * 3,
            };

            // Exposure: outstanding of ALL active loans
            $exposureNow = (float) Loan::where('user_id', $baseLoan->user_id)
                ->where('status', 'active')
                ->get()
                ->sum(fn($l) => (float) $l->outstandingBalance());

            $headroom = max(0, (float)$maxAllowed - (float)$exposureNow);

            if ($topUpAmount > $headroom) {
                throw new \Exception(
                    'Top-up exceeds allowed limit based on savings base. Available headroom: '
                        . number_format($headroom) . ' RWF'
                );
            }
            $duration = max(1, (int)($rules->loan_duration_months ?? $baseLoan->duration_months ?? 1));

            $interestAmount = $this->computeInterest(
                principal: $topUpAmount,
                rate: $interestRate,
                basis: $interestBasis,
                durationMonths: $duration,
                termMonths: $interestTermMonths
            );

            $totalPayable = round($topUpAmount + $interestAmount, 2);
            $monthlyInstallment = round($totalPayable / $duration, 2);



            // Create NEW loan (top-up)
            $interestRate = (float)($rules->loan_interest_rate ?? 0);

            $topUpLoan = Loan::create([
                'user_id'             => $baseLoan->user_id,
                'principal'           => $topUpAmount,
                'interest_rate'       => $interestRate,
                'interest_basis'       => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest_amount'      => $interestAmount,

                'total_payable'       => $totalPayable,
                'duration_months'     => $duration,
                'issued_date'         => now($tz)->toDateString(),
                'due_date'            => Carbon::parse($dueDate, $tz)->toDateString(),
                'status'              => 'active',
                'repayment_mode'      => 'installment',
                'monthly_installment' => $monthlyInstallment,
                'approved_by'         => $recordedBy,

                'rate_set_by'          => $recordedBy,
                'rate_set_at'          => now($tz),
                'rate_notes'           => $rateNotes,
            ]);

            // Ledger OUT
            $this->ledger->record(
                type: 'loan_disbursement',
                debit: $topUpAmount,
                credit: 0,
                userId: $baseLoan->user_id,
                reference: "Top-up Loan ID {$topUpLoan->id} (Base Loan {$baseLoan->id})",
                createdBy: $recordedBy,
                sourceType: 'loan',
                sourceId: $topUpLoan->id
            );

            return $topUpLoan;
        });
    }

    /**
     * Disburse Preview: member + rules + computed totals + whether guarantor is required + candidates.
     * Use this for the AdminLoansView modal.
     */
    public function disbursePreview(
        int $memberId,
        float $principal,
        User $viewer,
        float $interestRate,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $repaymentMode = null,
        ?int $durationMonths = null
    ): array {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $rules = SystemRule::firstOrFail();

        $member = User::query()
            ->select(['id', 'name', 'email', 'phone'])
            ->findOrFail($memberId);

        $requested = round(max(0, (float)$principal), 2);

        $interestRate = round((float)$interestRate, 2);
        if ($interestRate <= 0) {
            throw new \Exception('Interest rate is required and must be greater than 0.');
        }

        $interestBasis = strtolower(trim($interestBasis));
        if (!in_array($interestBasis, ['per_month', 'per_year', 'per_term'], true)) {
            throw new \Exception('Invalid interest basis. Use per_month, per_year, or per_term.');
        }
        if ($interestBasis === 'per_term') {
            $interestTermMonths = $interestTermMonths ? (int)$interestTermMonths : 0;
            if ($interestTermMonths <= 0) {
                throw new \Exception('interest_term_months is required when interest_basis is per_term.');
            }
        } else {
            $interestTermMonths = null;
        }

        // $hasActive = Loan::where('user_id', $memberId)->where('status', 'active')->exists();
        $activeLoans = Loan::where('user_id', $memberId)
            ->where('status', 'active')
            ->get();
        $hasActive = $activeLoans->isNotEmpty();

        // Accurate exposure: outstanding of all active loans
        $exposureNow = (float) $activeLoans->sum(fn($l) => (float) $l->outstandingBalance());
        $exposureNow = round($exposureNow, 2);

        $months = 0;
        if ((int)($rules->min_contribution_months ?? 0) > 0) {
            $months = (int) $this->contributionService->contributedMonthsCount($memberId);
        }

        // IMPORTANT: use savings base (includes opening balance)
        $savingBase = (float) $this->contributionService->savingsBaseForLoanLimit($memberId);

        $maxAllowed = match ($rules->loan_limit_type) {
            'multiple' => $savingBase * (float)($rules->loan_limit_value ?? 3),
            'equal'    => $savingBase,
            'fixed'    => (float)($rules->loan_limit_value ?? 0),
            default    => $savingBase * 3,
        };
        $maxAllowed = round(max(0, $maxAllowed), 2);

        $shortfall = round(max(0, $requested - $maxAllowed), 2);

        $mode = $repaymentMode ?: ($rules->loan_default_repayment_mode ?? 'once');
        $mode = in_array($mode, ['once', 'installment'], true) ? $mode : 'once';

        $duration = $durationMonths ?? max(1, (int)($rules->loan_duration_months ?? 1));
        $duration = max(1, (int)$duration);

        $interest = $this->computeInterest(
            principal: $requested,
            rate: $interestRate,
            basis: $interestBasis,
            durationMonths: $duration,
            termMonths: $interestTermMonths
        );

        $totalPayable = round($requested + $interest, 2);

        $monthlyInstallment = null;
        if ($mode === 'installment') {
            $monthlyInstallment = round($totalPayable / $duration, 2);
        }

        $blockedReasons = [];

        if (!(bool)$rules->allow_multiple_active_loans && $hasActive) {
            $blockedReasons[] = 'Member already has an active loan.';
        }

        $minMonths = (int)($rules->min_contribution_months ?? 0);
        if ($minMonths > 0 && $months < $minMonths) {
            $blockedReasons[] = "Member must have at least {$minMonths} contribution months.";
        }

        $candidates = User::query()
            ->select(['id', 'name', 'email', 'phone'])
            ->where('id', '!=', $memberId)
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'email' => $u->email,
            ])
            ->values();

        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'email' => $member->email,
            ],
            'rules' => [
                'allow_multiple_active_loans' => (bool)($rules->allow_multiple_active_loans ?? false),
                'min_contribution_months'     => (int)($rules->min_contribution_months ?? 0),
                'loan_limit_type'             => (string)($rules->loan_limit_type ?? ''),
                'loan_limit_value'            => (float)($rules->loan_limit_value ?? 0),
                'loan_default_repayment_mode' => (string)($rules->loan_default_repayment_mode ?? 'once'),
                'loan_duration_months'        => (int)($rules->loan_duration_months ?? 1),
            ],
            'totals' => [
                'savings_base'        => round($savingBase, 2),
                'contribution_months' => (int)$months,
                'has_active_loan'     => (bool)$hasActive,
                'active_loan_exposure' => $exposureNow,
            ],
            'eligibility' => [
                'max_allowed'         => round($maxAllowed, 2),
                'requested_principal' => round($requested, 2),
                'shortfall'           => round($shortfall, 2),
                'requires_guarantor'  => $shortfall > 0,
                'blocked'             => count($blockedReasons) > 0,
                'blocked_reasons'     => $blockedReasons,
            ],
            'preview' => [
                'interest_rate'        => round($interestRate, 2),
                'interest_basis'       => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest'             => round($interest, 2),
                'total_payable'        => round($totalPayable, 2),
                'repayment_mode'       => $mode,
                'duration_months'      => $duration,
                'monthly_installment'  => $monthlyInstallment,
            ],
            'guarantor_candidates' => $candidates,
        ];
    }

    protected function allocateToInstallments(Loan $loan, float $amount, Carbon $paidAt, int $recordedBy): void
    {
        if (($loan->repayment_mode ?? 'once') !== 'installment') return;

        $remaining = round((float) $amount, 2);
        if ($remaining <= 0) return;

        $installments = $loan->installments()
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('installment_no')
            ->lockForUpdate() // important inside your outer transaction
            ->get();

        foreach ($installments as $inst) {
            if ($remaining <= 0) break;

            $due  = round((float) ($inst->amount_due ?? 0), 2);
            $paid = round((float) ($inst->paid_amount ?? 0), 2);

            // If due is 0 (bad data), don't auto-mark as paid. Skip and investigate.
            if ($due <= 0) {
                continue;
            }

            $need = round(max(0, $due - $paid), 2);
            if ($need <= 0) {
                if ($inst->status !== 'paid') {
                    $inst->status = 'paid';
                    $inst->paid_date = $inst->paid_date ?: $paidAt->toDateString();
                    $inst->save();
                }
                continue;
            }

            // Apply penalty if overdue
            if (
                $inst->due_date &&
                $paidAt->gt(Carbon::parse($inst->due_date)) &&
                empty($inst->penalty_applied_at)
            ) {
                $penalty = $this->penaltyService->loanInstallmentLate(
                    memberId: $loan->user_id,
                    loanId: $loan->id,
                    installmentId: $inst->id,
                    recordedBy: $recordedBy,
                    baseAmount: (float) $need // remaining unpaid amount for this installment
                );
                $inst->penalty_applied_at = $paidAt; // cast datetime ok
                $inst->penalty_id = $penalty->id ?? null;
            }

            // ✅ Allocate payment to this installment
            $pay = round(min($need, $remaining), 2);

            $inst->paid_amount = round($paid + $pay, 2);

            // Status + paid_date
            if ($inst->paid_amount >= $due) {
                $inst->status = 'paid';
                $inst->paid_date = $inst->paid_date ?: $paidAt->toDateString();
            } else {
                $inst->status = 'partial';
                // paid_date should stay null until fully paid
            }

            $inst->save();
            $remaining = round($remaining - $pay, 2);
        }
    }
    public function repayPreview(int $loanId, User $viewer): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer', 'chair'], true)) {
            throw new \Exception('Forbidden');
        }

        $tz = 'Africa/Kigali';

        $loan = Loan::with(['user:id,name,phone,email'])
            ->findOrFail($loanId);

        $outstandingTotal = round((float) $loan->outstandingBalance(), 2);

        // Default values (for "once" loans)
        $nextInstallment = null;
        $displayDueDate = $loan->due_date ? Carbon::parse($loan->due_date, $tz)->toDateString() : null;

        // If installment mode: fetch next unpaid/partial installment
        if (($loan->repayment_mode ?? 'once') === 'installment') {
            $inst = LoanInstallment::query()
                ->where('loan_id', $loan->id)
                ->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('installment_no')
                ->first();

            if ($inst) {
                $amountDue = round((float) $inst->amount_due, 2);
                $paidAmt   = round((float) ($inst->paid_amount ?? 0), 2);
                $remain    = round(max(0, $amountDue - $paidAmt), 2);

                $nextInstallment = [
                    'id' => $inst->id,
                    'installment_no' => (int) $inst->installment_no,
                    'due_date' => $inst->due_date ? Carbon::parse($inst->due_date, $tz)->toDateString() : null,
                    'amount_due' => $amountDue,
                    'paid_amount' => $paidAmt,
                    'remaining' => $remain,
                    'status' => (string) $inst->status,
                ];

                // ✅ Use installment due date in modal instead of loan.due_date
                $displayDueDate = $nextInstallment['due_date'];
            }
        }

        return [
            'loan' => [
                'id' => $loan->id,
                'member' => [
                    'id' => $loan->user_id,
                    'name' => $loan->user?->name,
                    'phone' => $loan->user?->phone,
                    'email' => $loan->user?->email,
                ],
                'principal' => round((float) $loan->principal, 2),
                'total_payable' => round((float) $loan->total_payable, 2),
                'repayment_mode' => (string) ($loan->repayment_mode ?? 'once'),
                'loan_due_date' => $loan->due_date ? Carbon::parse($loan->due_date, $tz)->toDateString() : null,
            ],
            'outstanding' => [
                'total' => $outstandingTotal,
                'principal' => round((float) $loan->outstandingPrincipal(), 2),
                'interest' => round((float) $loan->outstandingInterest(), 2),
            ],
            'next_installment' => $nextInstallment, // null for "once"
            'display_due_date' => $displayDueDate,   // installment due date when available
        ];
    }
    public function computeInterest(
        float $principal,
        float $rate,
        string $basis,
        int $durationMonths,
        ?int $termMonths = null
    ): float {
        if ($principal <= 0 || $rate <= 0 || $durationMonths <= 0) return 0;

        $basis = strtolower($basis);

        // ✅ WHOLE LOAN TERM (flat)
        if ($basis === 'per_loan') {
            return round($principal * ($rate / 100), 2);
        }
        if ($basis === 'per_month') {
            return round($principal * ($rate / 100) * $durationMonths, 2);
        }

        if ($basis === 'per_year') {
            $monthlyRate = $rate / 12;
            return round($principal * ($monthlyRate / 100) * $durationMonths, 2);
        }

        if ($basis === 'per_term') {
            $termMonths = max(1, (int)($termMonths ?? 1));
            $terms = $durationMonths / $termMonths; // prorated
            return round($principal * ($rate / 100) * $terms, 2);
        }

        // Safe fallback
        return round($principal * ($rate / 100), 2);
    }
}
