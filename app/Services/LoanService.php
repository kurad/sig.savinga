<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Loan;
use App\Models\User;
use App\Models\SystemRule;
use App\Models\LoanRepayment;
use App\Models\LoanInstallment;
use App\Models\FinancialYearRule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoanService
{
    public function __construct(
        protected TransactionService $ledger,
        protected PenaltyService $penaltyService,
        protected ContributionService $contributionService
    ) {}

    protected function validateOwner(int $userId, ?int $beneficiaryId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required and must be a valid positive integer.');
        }

        if (!is_null($beneficiaryId) && $beneficiaryId <= 0) {
            throw new InvalidArgumentException('beneficiary_id must be a valid positive integer when provided.');
        }
    }

    protected function ownerLoanQuery(int $userId, ?int $beneficiaryId)
    {
        $this->validateOwner($userId, $beneficiaryId);

        $query = Loan::query()->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            $query->whereNull('beneficiary_id');
        } else {
            $query->where('beneficiary_id', $beneficiaryId);
        }

        return $query;
    }

    protected function ownerPayload(int $userId, ?int $beneficiaryId): array
    {
        $this->validateOwner($userId, $beneficiaryId);

        return [
            'user_id' => $userId,
            'beneficiary_id' => $beneficiaryId,
        ];
    }

    private function resolveActiveFy(): FinancialYearRule
    {
        return FinancialYearRule::where('is_active', true)->firstOrFail();
    }

    private function resolveFyByDate(Carbon $date): FinancialYearRule
    {
        $d = $date->toDateString();

        $fy = FinancialYearRule::query()
            ->whereDate('start_date', '<=', $d)
            ->whereDate('end_date', '>=', $d)
            ->orderByDesc('start_date')
            ->first();

        return $fy ?: $this->resolveActiveFy();
    }

    private function fyForLoan(Loan $loan): FinancialYearRule
    {
        $tz = 'Africa/Kigali';

        $issued = $loan->issued_date
            ? Carbon::parse($loan->issued_date, $tz)
            : ($loan->created_at ? Carbon::parse($loan->created_at, $tz) : Carbon::now($tz));

        return $this->resolveFyByDate($issued);
    }

    private function fyForNewLoan(?string $issuedDateYmd = null): FinancialYearRule
    {
        $tz = 'Africa/Kigali';
        $dt = $issuedDateYmd ? Carbon::parse($issuedDateYmd, $tz) : Carbon::now($tz);

        return $this->resolveFyByDate($dt);
    }

    public function listLoans(
        ?int $userId = null,
        ?int $beneficiaryId = null,
        ?string $status = null,
        int $perPage = 15
    ) {
        $query = Loan::query()
            ->with([
                'user:id,name,email,phone',
                'beneficiary:id,guardian_user_id,name,relationship',
                'beneficiary.guardian:id,name,email,phone',
                'baseLoan:id',
            ])
            ->when(!is_null($userId), fn($q) => $q->where('user_id', $userId))
            ->when(!is_null($userId) && is_null($beneficiaryId), fn($q) => $q->whereNull('beneficiary_id'))
            ->when(!is_null($beneficiaryId), fn($q) => $q->where('beneficiary_id', $beneficiaryId))
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at');

        $p = $query->paginate($perPage);

        $p->getCollection()->transform(function (Loan $loan) {
            $mode = (string) ($loan->repayment_mode ?? 'once');

            $loan->is_migrated = (bool) $loan->is_migrated;
            $principal      = round((float) $loan->principal, 2);
            $interestAmount = round((float) ($loan->interest_amount ?? 0), 2);
            $totalPayable   = round((float) $loan->total_payable, 2);

            if ($mode === 'installment') {
                $duration = max(1, (int) ($loan->duration_months ?? 1));
                $stored = (float) ($loan->monthly_installment ?? 0);

                $loan->monthly_installment = $stored > 0
                    ? round($stored, 2)
                    : round($totalPayable / $duration, 2);
            } else {
                $loan->monthly_installment = $loan->monthly_installment !== null
                    ? round((float) $loan->monthly_installment, 2)
                    : null;
            }

            $outTotal = round(max(0, (float) $loan->outstandingBalance()), 2);
            $outPr    = round(max(0, (float) $loan->outstandingPrincipal()), 2);
            $outInt   = round(max(0, (float) $loan->outstandingInterest()), 2);

            $loan->outstanding = [
                'total' => $outTotal,
                'principal' => $outPr,
                'interest_amount' => $outInt,
                'interest' => $outInt,
            ];

            $loan->installments_meta = null;
            if ($mode === 'installment') {
                [$paidCount, $totalCount, $next] = $this->installmentStatsForLoan((int) $loan->id);
                $loan->installments_meta = [
                    'paid' => (int) $paidCount,
                    'total' => (int) $totalCount,
                    'next' => $next,
                ];
            }

            $loan->base_loan_id = $loan->base_loan_id ? (int) $loan->base_loan_id : null;
            $loan->is_top_up = !is_null($loan->base_loan_id);

            $loan->terms = [
                'interest_rate' => round((float) $loan->interest_rate, 2),
                'interest_basis' => (string) ($loan->interest_basis ?? 'per_year'),
                'interest_term_months' => $loan->interest_term_months ? (int) $loan->interest_term_months : null,
                'interest_amount' => $interestAmount,
                'interest' => $interestAmount,
                'principal' => $principal,
                'total_payable' => $totalPayable,
            ];

            $loan->principal = $principal;
            $loan->interest_amount = $interestAmount;
            $loan->total_payable = $totalPayable;

            $loan->issued_date = $loan->issued_date ? Carbon::parse($loan->issued_date)->toDateString() : null;
            $loan->due_date = $loan->due_date ? Carbon::parse($loan->due_date)->toDateString() : null;

            return $loan;
        });

        return $p;
    }

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
            $due = round((float) ($next->amount_due ?? 0), 2);
            $paid = round((float) ($next->paid_amount ?? 0), 2);
            $remaining = round(max(0, $due - $paid), 2);

            $nextInstallment = [
                'id' => (int) $next->id,
                'installment_no' => (int) $next->installment_no,
                'due_date' => $next->due_date ? Carbon::parse($next->due_date)->toDateString() : null,
                'amount_due' => $due,
                'paid_amount' => $paid,
                'remaining' => $remaining,
                'status' => (string) $next->status,
            ];
        }

        return [$paidCount, $totalCount, $nextInstallment];
    }

    public function disburse(
        int $userId,
        ?int $beneficiaryId,
        float $principal,
        string $dueDate,
        int $recordedBy,
        ?string $repaymentMode = null,
        ?int $durationMonths = null,
        array $guarantors = [],
        ?float $interestRate = 0,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $rateNotes = null
    ): Loan {
        $this->validateOwner($userId, $beneficiaryId);

        return DB::transaction(function () use (
            $userId,
            $beneficiaryId,
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

            $issuedDate = Carbon::now($tz)->toDateString();
            $fy = $this->fyForNewLoan($issuedDate);

            $principal = round(max(0, (float) $principal), 2);
            if ($principal <= 0) {
                throw new \Exception('Principal must be greater than 0.');
            }

            [$interestRate, $interestBasis, $interestTermMonths] = $this->normalizeInterestInputs(
                $interestRate,
                $interestBasis,
                $interestTermMonths
            );

            if (!(bool) ($rules->allow_multiple_active_loans ?? false)) {
                $hasActive = $this->ownerLoanQuery($userId, $beneficiaryId)
                    ->where('status', 'active')
                    ->exists();

                if ($hasActive) {
                    throw new \Exception('Owner already has an active loan.');
                }
            }

            $minMonths = (int) ($rules->min_contribution_months ?? 0);
            if ($minMonths > 0) {
                $months = (int) $this->contributionService->contributedMonthsCount(
                    userId: $userId,
                    beneficiaryId: $beneficiaryId,
                    financialYearRuleId: (int) $fy->id
                );

                if ($months < $minMonths) {
                    throw new \Exception("Owner must have at least {$minMonths} contribution months.");
                }
            }

            $savingBase = (float) $this->ledger->savingsBaseForLoanLimit($userId, $beneficiaryId);
            $maxAllowed = $this->maxAllowedFromRules($rules, $savingBase);
            $shortfall = round(max(0, $principal - $maxAllowed), 2);

            if ($shortfall > 0) {
                if (!is_array($guarantors) || count($guarantors) === 0) {
                    throw new \Exception('Loan exceeds allowed limit. Guarantor pledge required.');
                }

                $pledged = 0.0;
                $seen = [];

                foreach ($guarantors as $g) {
                    $gid = (int) ($g['user_id'] ?? 0);
                    $amt = round((float) ($g['amount'] ?? 0), 2);

                    if ($gid <= 0 || $amt <= 0) {
                        continue;
                    }

                    if ($gid === $userId) {
                        throw new \Exception('Borrower cannot be a guarantor.');
                    }

                    if (isset($seen[$gid])) {
                        continue;
                    }
                    $seen[$gid] = true;

                    if (!User::whereKey($gid)->exists()) {
                        throw new \Exception("Guarantor user #{$gid} not found.");
                    }

                    $pledged += $amt;
                }

                $pledged = round($pledged, 2);
                if ($pledged + 0.0001 < $shortfall) {
                    throw new \Exception(
                        'Not enough guarantor pledge. Need at least ' . number_format($shortfall, 2) . ' RWF'
                    );
                }
            }

            $mode = $repaymentMode ?: ($rules->loan_default_repayment_mode ?? 'once');
            $mode = in_array($mode, ['once', 'installment'], true) ? $mode : 'once';

            $duration = $durationMonths ?? (int) ($rules->loan_duration_months ?? 1);
            $duration = max(1, (int) $duration);

            $interestMonths = ($mode === 'installment') ? $duration : 1;

            $interestAmount = $this->computeInterest(
                principal: $principal,
                rate: $interestRate,
                basis: $interestBasis,
                durationMonths: $interestMonths,
                termMonths: $interestTermMonths
            );

            $interestAmount = round((float) $interestAmount, 2);
            $totalPayable = round($principal + $interestAmount, 2);

            $netDisbursed = round($principal - $interestAmount, 2);
            if ($netDisbursed < 0) {
                throw new \Exception('Interest is greater than principal; cannot disburse a negative amount.');
            }

            $remainingOutstanding = round($totalPayable - $interestAmount, 2);
            $monthlyInstallment = null;

            if ($mode === 'installment') {
                $monthlyInstallment = round($remainingOutstanding / $duration, 2);
            }

            $computedDueDate = $mode === 'installment'
                ? Carbon::parse($issuedDate, 'Africa/Kigali')->addMonthsNoOverflow($duration)->toDateString()
                : Carbon::parse($dueDate, 'Africa/Kigali')->toDateString();

            $loan = Loan::create([
                ...$this->ownerPayload($userId, $beneficiaryId),
                'principal' => $principal,
                'interest_rate' => $interestRate,
                'interest_basis' => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest_amount' => $interestAmount,
                'total_payable' => $totalPayable,
                'duration_months' => $duration,
                'issued_date' => $issuedDate,
                'due_date' => $computedDueDate,
                'status' => 'active',
                'repayment_mode' => $mode,
                'monthly_installment' => $monthlyInstallment,
                'approved_by' => $recordedBy,
                'rate_set_by' => $recordedBy,
                'rate_set_at' => now('Africa/Kigali'),
                'rate_notes' => $rateNotes,
            ]);

            if ($mode === 'installment') {
                $base = round($remainingOutstanding / $duration, 2);
                $sumFirst = round($base * ($duration - 1), 2);
                $lastAmt = round($remainingOutstanding - $sumFirst, 2);

                for ($i = 1; $i <= $duration; $i++) {
                    $instDue = Carbon::parse($issuedDate, 'Africa/Kigali')->addMonthsNoOverflow($i)->toDateString();
                    $amt = ($i === $duration) ? $lastAmt : $base;

                    LoanInstallment::create([
                        'loan_id' => $loan->id,
                        'installment_no' => $i,
                        'due_date' => $instDue,
                        'amount_due' => $amt,
                        'status' => 'unpaid',
                        'paid_amount' => 0,
                    ]);
                }
            }

            if ($shortfall > 0) {
                $seen = [];

                foreach ($guarantors as $g) {
                    $gid = (int) ($g['user_id'] ?? 0);
                    $amt = round((float) ($g['amount'] ?? 0), 2);

                    if ($gid <= 0 || $amt <= 0) {
                        continue;
                    }
                    if ($gid === $userId) {
                        continue;
                    }
                    if (isset($seen[$gid])) {
                        continue;
                    }
                    $seen[$gid] = true;

                    \App\Models\LoanGuarantor::create([
                        'loan_id' => $loan->id,
                        'guarantor_user_id' => $gid,
                        'pledged_amount' => $amt,
                        'status' => 'active',
                    ]);
                }
            }

            $this->ledger->record(
                type: 'loan_disbursement',
                debit: $netDisbursed,
                credit: 0,
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                reference: "Loan ID {$loan->id} (Gross {$principal}, Interest withheld {$interestAmount})",
                createdBy: $recordedBy,
                sourceType: 'loan',
                sourceId: $loan->id
            );

            $this->recordUpfrontInterestDeduction(
                loan: $loan,
                interestAmount: $interestAmount,
                recordedBy: $recordedBy,
                issuedDateYmd: $issuedDate
            );

            return $loan;
        });
    }

    public function repay(int $loanId, float $amount, string $paidDate, int $recordedBy): LoanRepayment
    {
        return DB::transaction(function () use ($loanId, $amount, $paidDate, $recordedBy) {
            $tz = 'Africa/Kigali';

            $loan = Loan::lockForUpdate()->findOrFail($loanId);
            $paid = Carbon::parse($paidDate, $tz);

            if ($loan->status !== 'active') {
                throw new \Exception('Only active loans can be repaid.');
            }

            $amount = round((float) $amount, 2);
            if ($amount <= 0) {
                throw new \Exception('Repayment amount must be greater than 0.');
            }

            $outstanding = round((float) $loan->outstandingBalance(), 2);
            if ($amount > $outstanding + 0.0001) {
                throw new \Exception('Repayment exceeds outstanding loan balance.');
            }

            return $this->repayLocked($loan, $amount, $paid, $recordedBy);
        });
    }

    public function repayWithAutoSplit(int $loanId, float $amount, string $paidDate, int $recordedBy): array
    {
        return DB::transaction(function () use ($loanId, $amount, $paidDate, $recordedBy) {
            $tz = 'Africa/Kigali';

            $loan = Loan::lockForUpdate()->findOrFail($loanId);

            if ($loan->status !== 'active') {
                throw new \Exception('Only active loans can be repaid.');
            }

            $paid = Carbon::parse($paidDate, $tz);
            $paidDateYmd = $paid->toDateString();

            $amount = round((float) $amount, 2);
            if ($amount <= 0) {
                throw new \Exception('Repayment amount must be greater than 0.');
            }

            $fy = $this->fyForLoan($loan);
            $outstanding = round((float) $loan->outstandingBalance(), 2);

            if ($outstanding <= 0) {
                $contrib = $this->contributionService->record(
                    userId: (int) $loan->user_id,
                    beneficiaryId: $loan->beneficiary_id,
                    amount: $amount,
                    expectedDate: $paidDateYmd,
                    paidDate: $paidDateYmd,
                    recordedBy: $recordedBy,
                    period: $paid->format('Y-m'),
                    financialYearRuleId: (int) $fy->id
                );

                return [
                    'message' => 'Loan is already settled. Amount recorded as contribution.',
                    'loan' => [
                        'id' => $loan->id,
                        'status' => $loan->status,
                        'is_migrated' => $loan->is_migrated,
                        'outstanding_balance' => round((float) $loan->outstandingBalance(), 2),
                        'outstanding_principal' => round((float) $loan->outstandingPrincipal(), 2),
                        'outstanding_interest' => round((float) $loan->outstandingInterest(), 2),
                    ],
                    'repayment' => null,
                    'extra_contribution' => $contrib,
                    'financial_year_rule_id_used' => (int) $fy->id,
                ];
            }

            $repayAmount = round(min($amount, $outstanding), 2);
            $extraAmount = round(max(0, $amount - $outstanding), 2);

            $repayment = $this->repayLocked(
                loan: $loan,
                amount: $repayAmount,
                paid: $paid,
                recordedBy: $recordedBy
            );

            $loan->refresh();

            $extraContribution = null;
            $extraAllocationsTotal = 0.0;

            if ($extraAmount > 0) {
                $extraContribution = $this->contributionService->record(
                    userId: (int) $loan->user_id,
                    beneficiaryId: $loan->beneficiary_id,
                    amount: $extraAmount,
                    expectedDate: $paidDateYmd,
                    paidDate: $paidDateYmd,
                    recordedBy: $recordedBy,
                    period: $paid->format('Y-m'),
                    financialYearRuleId: (int) $fy->id
                );

                $extraAllocationsTotal = round(
                    collect($extraContribution['allocations'] ?? [])->sum(fn($a) => (float) ($a['allocated'] ?? 0)),
                    2
                );
            }

            return [
                'message' => $extraAmount > 0
                    ? 'Repayment recorded and extra amount saved as contribution.'
                    : 'Repayment recorded successfully.',
                'loan' => [
                    'id' => $loan->id,
                    'status' => $loan->status,
                    'outstanding_balance' => round((float) $loan->outstandingBalance(), 2),
                    'outstanding_principal' => round((float) $loan->outstandingPrincipal(), 2),
                    'outstanding_interest' => round((float) $loan->outstandingInterest(), 2),
                ],
                'repayment' => [
                    'id' => $repayment->id,
                    'amount' => (float) $repayment->amount,
                    'principal_component' => (float) $repayment->principal_component,
                    'interest_component' => (float) $repayment->interest_component,
                    'paid_date' => $repayment->repayment_date ? $repayment->repayment_date->toDateString() : $paidDateYmd,
                ],
                'extra_contribution' => $extraContribution,
                'extra_contribution_total' => $extraAllocationsTotal,
                'financial_year_rule_id_used' => (int) $fy->id,
            ];
        });
    }

    private function repayLocked(Loan $loan, float $amount, Carbon $paid, int $recordedBy): LoanRepayment
    {
        $tz = 'Africa/Kigali';

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            throw new \Exception('Repayment amount must be greater than 0.');
        }

        $mode = (string) ($loan->repayment_mode ?? 'once');

        if ($mode === 'once') {
            $due = $loan->due_date ? Carbon::parse($loan->due_date, $tz) : null;

            if ($due && $paid->gt($due)) {
                $this->penaltyService->loanLate(
                    userId: (int) $loan->user_id,
                    beneficiaryId: $loan->beneficiary_id,
                    loanId: $loan->id,
                    recordedBy: $recordedBy,
                    periodKey: $paid->format('Y-m'),
                    principalBase: (float) $loan->outstandingPrincipal(),
                    date: $paid->toDateString()
                );
            }
        }

        if ($loan->is_migrated) {
            [$interestComponent, $principalComponent] = $this->allocateRepaymentInterestFirst($loan, $amount);
        } elseif ($mode === 'installment') {
            $totalPayable = round((float) $loan->total_payable, 2);
            $totalInterest = round((float) ($loan->interest_amount ?? 0), 2);

            $ratio = $totalPayable > 0 ? ($totalInterest / $totalPayable) : 0.0;

            $interestComponent = round($amount * $ratio, 2);
            $principalComponent = round($amount - $interestComponent, 2);
        } else {
            [$interestComponent, $principalComponent] = $this->allocateRepaymentInterestFirst($loan, $amount);
        }

        $repayment = LoanRepayment::create([
            'loan_id' => $loan->id,
            'amount' => $amount,
            'interest_component' => $interestComponent,
            'principal_component' => $principalComponent,
            'repayment_date' => $paid,
            'recorded_by' => $recordedBy,
        ]);

        $this->allocateToInstallments($loan, $amount, $paid, $recordedBy);

        $this->ledger->record(
            type: 'loan_repayment',
            debit: 0,
            credit: $amount,
            userId: (int) $loan->user_id,
            beneficiaryId: $loan->beneficiary_id,
            reference: 'Loan repayment ID ' . $repayment->id . ' for Loan ID ' . $loan->id,
            createdBy: $recordedBy,
            sourceType: 'loan_repayment',
            sourceId: $repayment->id
        );

        $loan->refresh();

        if (round((float) $loan->outstandingBalance(), 2) <= 0) {
            $loan->update(['status' => 'completed']);

            \App\Models\LoanGuarantor::where('loan_id', $loan->id)
                ->where('status', 'active')
                ->update(['status' => 'released']);
        }

        return $repayment;
    }

    protected function allocateRepaymentInterestFirst(Loan $loan, float $amount): array
    {
        $interestOutstanding = (float) $loan->outstandingInterest();
        $principalOutstanding = (float) $loan->outstandingPrincipal();

        $interestComponent = min($amount, $interestOutstanding);
        $remaining = $amount - $interestComponent;

        $principalComponent = min($remaining, $principalOutstanding);

        return [round($interestComponent, 2), round($principalComponent, 2)];
    }

    public function memberLoanSummary(User $viewer, User $member, ?string $from = null, ?string $to = null): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true) && $viewer->id !== $member->id) {
            throw new \Exception('Forbidden');
        }

        $fromDt = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDt = $to ? Carbon::parse($to)->endOfDay() : null;

        $loanQ = Loan::where('user_id', $member->id)->whereNull('beneficiary_id');
        if ($fromDt) $loanQ->where('created_at', '>=', $fromDt);
        if ($toDt) $loanQ->where('created_at', '<=', $toDt);

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
        $totalInterestCharged = (float) $loans->sum(fn($l) => (float) $l->total_payable - (float) $l->principal);

        $totalRepaidAmount = (float) $repayments->sum('amount');
        $principalRepaid = (float) $repayments->sum('principal_component');
        $interestPaid = (float) $repayments->sum('interest_component');

        $allLoansNow = Loan::where('user_id', $member->id)->whereNull('beneficiary_id')->get();

        $outstandingTotal = (float) $allLoansNow->sum(fn($l) => (float) $l->outstandingBalance());
        $outstandingPrincipal = (float) $allLoansNow->sum(fn($l) => (float) $l->outstandingPrincipal());
        $outstandingInterest = (float) $allLoansNow->sum(fn($l) => (float) $l->outstandingInterest());

        $activeCount = (int) $allLoansNow->where('status', 'active')->count();
        $completedCount = (int) $allLoansNow->where('status', 'completed')->count();

        $today = Carbon::today();
        $activeLoans = $allLoansNow->where('status', 'active');

        $overdueCount = (int) $activeLoans->filter(function ($loan) use ($today) {
            $due = $loan->due_date ? Carbon::parse($loan->due_date) : null;
            return $due && $due->lt($today) && (float) $loan->outstandingBalance() > 0;
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
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'totals_in_range' => [
                    'principal_borrowed' => round($totalBorrowedPrincipal, 2),
                    'interest_charged' => round($totalInterestCharged, 2),
                    'total_repaid' => round($totalRepaidAmount, 2),
                    'principal_repaid' => round($principalRepaid, 2),
                    'interest_paid' => round($interestPaid, 2),
                ],
                'outstanding_now' => [
                    'total' => round($outstandingTotal, 2),
                    'principal' => round($outstandingPrincipal, 2),
                    'interest' => round($outstandingInterest, 2),
                ],
                'counts_now' => [
                    'active_loans' => $activeCount,
                    'completed_loans' => $completedCount,
                    'overdue_loans' => $overdueCount,
                ],
                'next_due_loan' => $nextDue ? [
                    'loan_id' => $nextDue->id,
                    'due_date' => Carbon::parse($nextDue->due_date)->toDateString(),
                    'outstanding_balance' => round((float) $nextDue->outstandingBalance(), 2),
                ] : null,
            ],
        ];
    }

    public function topUpPreview(int $baseLoanId, User $viewer): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $rules = SystemRule::firstOrFail();
        $loan = Loan::with('repayments')->findOrFail($baseLoanId);

        $minInst = (int) ($rules->min_installments_before_top_up ?? 3);
        $paidInst = (int) $loan->installmentsPaidCount();

        $tz = 'Africa/Kigali';
        $fy = $this->fyForNewLoan(Carbon::now($tz)->toDateString());

        $savingBase = (float) $this->ledger->savingsBaseForLoanLimit((int) $loan->user_id, $loan->beneficiary_id);
        $maxAllowed = $this->maxAllowedFromRules($rules, $savingBase);

        $activeLoans = $this->ownerLoanQuery((int) $loan->user_id, $loan->beneficiary_id)
            ->where('status', 'active')
            ->get();

        $exposureNow = (float) $activeLoans->sum(fn($l) => (float) $l->outstandingBalance());
        $headroom = max(0, (float) $maxAllowed - (float) $exposureNow);

        $enabled = (bool) ($rules->allow_loan_top_up ?? false);

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
                'beneficiary_id' => $loan->beneficiary_id,
                'status' => $loan->status,
                'repayment_mode' => $loan->repayment_mode,
                'monthly_installment' => (float) ($loan->monthly_installment ?? 0),
                'installments_paid' => $paidInst,
                'outstanding_balance' => round((float) $loan->outstandingBalance(), 2),
                'due_date' => $loan->due_date ? Carbon::parse($loan->due_date)->toDateString() : null,
            ],
            'rules' => [
                'allow_loan_top_up' => $enabled,
                'min_installments_before_top_up' => $minInst,
                'loan_limit_type' => (string) ($rules->loan_limit_type ?? ''),
                'loan_limit_value' => (float) ($rules->loan_limit_value ?? 0),
            ],
            'eligibility' => [
                'financial_year_rule_id_used' => (int) $fy->id,
                'savings_base' => round($savingBase, 2),
                'max_allowed' => round((float) $maxAllowed, 2),
                'active_loan_exposure' => round((float) $exposureNow, 2),
                'headroom' => round((float) $headroom, 2),
            ],
            'allowed' => $allowed,
            'reason' => $reason,
        ];
    }

    public function topUpAsNewLoan(
        int $baseLoanId,
        float $topUpAmount,
        int $recordedBy,
        float $interestRate,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $rateNotes = null,
        int $durationMonths = 1
    ): Loan {
        return DB::transaction(function () use (
            $baseLoanId,
            $topUpAmount,
            $recordedBy,
            $interestRate,
            $interestBasis,
            $interestTermMonths,
            $rateNotes,
            $durationMonths
        ) {
            $viewer = User::findOrFail($recordedBy);
            $preview = $this->topUpPreview($baseLoanId, $viewer);

            if (!($preview['allowed'] ?? false)) {
                throw new \Exception($preview['reason'] ?? 'Top-up not allowed.');
            }

            $tz = 'Africa/Kigali';
            $rules = SystemRule::firstOrFail();

            if (!(bool) ($rules->allow_loan_top_up ?? false)) {
                throw new \Exception('Loan top-up is not enabled in system rules.');
            }

            $headroom = (float) ($preview['eligibility']['headroom'] ?? 0);

            $topUpAmount = round((float) $topUpAmount, 2);
            if ($topUpAmount <= 0) {
                throw new \Exception('Top-up amount must be greater than 0.');
            }
            if ($topUpAmount > $headroom + 0.0001) {
                throw new \Exception('Top-up exceeds available headroom: ' . number_format($headroom, 2) . ' RWF');
            }

            [$interestRate, $interestBasis, $interestTermMonths] = $this->normalizeInterestInputs(
                $interestRate,
                $interestBasis,
                $interestTermMonths
            );

            $baseLoan = Loan::lockForUpdate()->findOrFail($baseLoanId);

            $duration = max(1, (int) $durationMonths);

            $issuedDate = now($tz)->toDateString();
            $dueDate = Carbon::parse($issuedDate, $tz)->addMonthsNoOverflow($duration)->toDateString();

            $interestAmount = $this->computeInterest(
                principal: $topUpAmount,
                rate: $interestRate,
                basis: $interestBasis,
                durationMonths: $duration,
                termMonths: $interestTermMonths
            );

            $interestAmount = round((float) $interestAmount, 2);
            $totalPayable = round($topUpAmount + $interestAmount, 2);

            $netDisbursed = round($topUpAmount - $interestAmount, 2);
            if ($netDisbursed < 0) {
                throw new \Exception('Interest is greater than principal; cannot disburse a negative amount.');
            }

            $remainingOutstanding = round($totalPayable - $interestAmount, 2);
            $monthlyInstallment = round($remainingOutstanding / $duration, 2);

            $topUpLoan = Loan::create([
                'user_id' => $baseLoan->user_id,
                'beneficiary_id' => $baseLoan->beneficiary_id,
                'base_loan_id' => $baseLoan->id,
                'principal' => $topUpAmount,
                'interest_rate' => $interestRate,
                'interest_basis' => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest_amount' => $interestAmount,
                'total_payable' => $totalPayable,
                'duration_months' => $duration,
                'issued_date' => $issuedDate,
                'due_date' => $dueDate,
                'status' => 'active',
                'repayment_mode' => 'installment',
                'monthly_installment' => $monthlyInstallment,
                'approved_by' => $recordedBy,
                'rate_set_by' => $recordedBy,
                'rate_set_at' => now($tz),
                'rate_notes' => $rateNotes,
            ]);

            $baseAmt = round($remainingOutstanding / $duration, 2);
            $sumFirst = round($baseAmt * ($duration - 1), 2);
            $lastAmt = round($remainingOutstanding - $sumFirst, 2);

            for ($i = 1; $i <= $duration; $i++) {
                $instDue = Carbon::parse($topUpLoan->issued_date, $tz)->addMonthsNoOverflow($i)->toDateString();
                $amt = ($i === $duration) ? $lastAmt : $baseAmt;

                LoanInstallment::create([
                    'loan_id' => $topUpLoan->id,
                    'installment_no' => $i,
                    'due_date' => $instDue,
                    'amount_due' => $amt,
                    'status' => 'unpaid',
                    'paid_amount' => 0,
                ]);
            }

            $this->ledger->record(
                type: 'loan_disbursement',
                debit: $netDisbursed,
                credit: 0,
                userId: (int) $baseLoan->user_id,
                beneficiaryId: $baseLoan->beneficiary_id,
                reference: "Top-up Loan ID {$topUpLoan->id} (Base Loan {$baseLoan->id}) (Gross {$topUpAmount}, Interest withheld {$interestAmount})",
                createdBy: $recordedBy,
                sourceType: 'loan',
                sourceId: $topUpLoan->id
            );

            $this->recordUpfrontInterestDeduction(
                loan: $topUpLoan,
                interestAmount: $interestAmount,
                recordedBy: $recordedBy,
                issuedDateYmd: $issuedDate
            );

            return $topUpLoan;
        });
    }

    public function topUpRefinance(
        int $baseLoanId,
        float $newPrincipalTotal,
        int $durationMonths,
        int $recordedBy,
        float $interestRate,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $rateNotes = null
    ): array {
        return DB::transaction(function () use (
            $baseLoanId,
            $newPrincipalTotal,
            $durationMonths,
            $recordedBy,
            $interestRate,
            $interestBasis,
            $interestTermMonths,
            $rateNotes
        ) {
            $tz = 'Africa/Kigali';

            $viewer = User::findOrFail($recordedBy);
            if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
                throw new \Exception('Forbidden');
            }

            $rules = SystemRule::firstOrFail();
            if (!(bool) ($rules->allow_loan_top_up ?? false)) {
                throw new \Exception('Loan top-up is disabled in system rules.');
            }

            [$interestRate, $interestBasis, $interestTermMonths] = $this->normalizeInterestInputs(
                $interestRate,
                $interestBasis,
                $interestTermMonths
            );

            $baseLoan = Loan::with('repayments')->lockForUpdate()->findOrFail($baseLoanId);

            if ($baseLoan->status !== 'active') {
                throw new \Exception('Only active loans can be topped up.');
            }
            if (($baseLoan->repayment_mode ?? 'once') !== 'installment') {
                throw new \Exception('Top-up is only allowed for installment loans.');
            }

            $minInst = (int) ($rules->min_installments_before_top_up ?? 3);
            $paidInst = (int) $baseLoan->installmentsPaidCount();
            if ($paidInst < $minInst) {
                throw new \Exception("Top-up allowed after {$minInst} installments. Paid so far: {$paidInst}.");
            }

            $newPrincipalTotal = round((float) $newPrincipalTotal, 2);
            if ($newPrincipalTotal <= 0) {
                throw new \Exception('Requested new loan amount must be greater than 0.');
            }

            $durationMonths = max(1, (int) $durationMonths);

            $baseOutstanding = round((float) $baseLoan->outstandingBalance(), 2);
            if ($baseOutstanding <= 0) {
                throw new \Exception('Base loan is already settled (no outstanding).');
            }

            if ($newPrincipalTotal <= $baseOutstanding) {
                throw new \Exception(
                    'Requested amount must be greater than outstanding balance (' . number_format($baseOutstanding, 2) . ').'
                );
            }

            $issuedDate = Carbon::now($tz)->toDateString();
            $fy = $this->fyForNewLoan($issuedDate);

            $savingBase = (float) $this->ledger->savingsBaseForLoanLimit(
                (int) $baseLoan->user_id,
                $baseLoan->beneficiary_id
            );

            $maxAllowed = $this->maxAllowedFromRules($rules, $savingBase);

            $activeLoans = $this->ownerLoanQuery((int) $baseLoan->user_id, $baseLoan->beneficiary_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            $exposureNow = round((float) $activeLoans->sum(fn($l) => (float) $l->outstandingBalance()), 2);

            $exposureAfterClose = round(max(0, $exposureNow - $baseOutstanding), 2);
            $headroomAfterClose = round(max(0, $maxAllowed - $exposureAfterClose), 2);

            if ($newPrincipalTotal > $headroomAfterClose + 0.0001) {
                throw new \Exception(
                    'Requested amount exceeds allowed limit. Max possible new total is '
                        . number_format($headroomAfterClose, 2)
                        . ' based on savings base and active exposure after closing the base loan.'
                );
            }

            $dueDate = Carbon::parse($issuedDate, $tz)->addMonthsNoOverflow($durationMonths)->toDateString();

            $interestAmount = $this->computeInterest(
                principal: $newPrincipalTotal,
                rate: $interestRate,
                basis: $interestBasis,
                durationMonths: $durationMonths,
                termMonths: $interestTermMonths
            );

            $interestAmount = round((float) $interestAmount, 2);
            $totalPayable = round($newPrincipalTotal + $interestAmount, 2);

            $netDisbursed = round($newPrincipalTotal - $interestAmount, 2);
            if ($netDisbursed < 0) {
                throw new \Exception('Interest is greater than principal; cannot disburse a negative amount.');
            }

            $remainingOutstanding = round($totalPayable - $interestAmount, 2);
            $monthlyInstallment = round($remainingOutstanding / $durationMonths, 2);

            $newLoan = Loan::create([
                'user_id' => $baseLoan->user_id,
                'beneficiary_id' => $baseLoan->beneficiary_id,
                'base_loan_id' => $baseLoan->id,
                'principal' => $newPrincipalTotal,
                'interest_rate' => $interestRate,
                'interest_basis' => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest_amount' => $interestAmount,
                'total_payable' => $totalPayable,
                'duration_months' => $durationMonths,
                'issued_date' => $issuedDate,
                'due_date' => $dueDate,
                'status' => 'active',
                'repayment_mode' => 'installment',
                'monthly_installment' => $monthlyInstallment,
                'approved_by' => $recordedBy,
                'rate_set_by' => $recordedBy,
                'rate_set_at' => now($tz),
                'rate_notes' => $rateNotes,
            ]);

            $baseAmt = round($remainingOutstanding / $durationMonths, 2);
            $sumFirst = round($baseAmt * ($durationMonths - 1), 2);
            $lastAmt = round($remainingOutstanding - $sumFirst, 2);

            for ($i = 1; $i <= $durationMonths; $i++) {
                $instDue = Carbon::parse($issuedDate, $tz)->addMonthsNoOverflow($i)->toDateString();
                $amt = ($i === $durationMonths) ? $lastAmt : $baseAmt;

                LoanInstallment::create([
                    'loan_id' => $newLoan->id,
                    'installment_no' => $i,
                    'due_date' => $instDue,
                    'amount_due' => $amt,
                    'status' => 'unpaid',
                    'paid_amount' => 0,
                ]);
            }

            $this->ledger->record(
                type: 'loan_disbursement',
                debit: $netDisbursed,
                credit: 0,
                userId: (int) $newLoan->user_id,
                beneficiaryId: $newLoan->beneficiary_id,
                reference: "Refinance Top-up New Loan ID {$newLoan->id} (Base Loan {$baseLoan->id}) (Gross {$newPrincipalTotal}, Interest withheld {$interestAmount})",
                createdBy: $recordedBy,
                sourceType: 'loan',
                sourceId: $newLoan->id
            );

            $this->recordUpfrontInterestDeduction(
                loan: $newLoan,
                interestAmount: $interestAmount,
                recordedBy: $recordedBy,
                issuedDateYmd: $issuedDate
            );

            $paidAt = Carbon::now($tz);

            $this->repayLocked(
                loan: $baseLoan,
                amount: $baseOutstanding,
                paid: $paidAt,
                recordedBy: $recordedBy
            );

            $cashOut = round($newPrincipalTotal - $baseOutstanding, 2);

            $baseLoan->refresh();
            $newLoan->refresh();

            return [
                'message' => 'Top-up refinance created. Base loan closed and new loan issued.',
                'base' => [
                    'id' => $baseLoan->id,
                    'status' => (string) $baseLoan->status,
                    'outstanding_before_close' => $baseOutstanding,
                    'outstanding_now' => round((float) $baseLoan->outstandingBalance(), 2),
                ],
                'new_loan' => $newLoan->load([
                    'user:id,name,email,phone',
                    'beneficiary:id,guardian_user_id,name,relationship',
                    'beneficiary.guardian:id,name,email,phone',
                ]),
                'cash_out' => $cashOut,
                'meta' => [
                    'financial_year_rule_id_used' => (int) $fy->id,
                    'savings_base' => round((float) $savingBase, 2),
                    'max_allowed' => round((float) $maxAllowed, 2),
                    'exposure_now' => $exposureNow,
                    'exposure_after_close' => $exposureAfterClose,
                    'headroom_after_close' => $headroomAfterClose,
                ],
            ];
        });
    }

    public function topUpsForLoan(int $baseLoanId, User $viewer): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $base = Loan::findOrFail($baseLoanId);

        $topUps = $base->topUps()
            ->orderBy('created_at')
            ->get()
            ->map(fn(Loan $l) => [
                'id' => $l->id,
                'user_id' => $l->user_id,
                'beneficiary_id' => $l->beneficiary_id,
                'principal' => round((float) $l->principal, 2),
                'total_payable' => round((float) $l->total_payable, 2),
                'outstanding' => round((float) $l->outstandingBalance(), 2),
                'issued_date' => $l->issued_date?->toDateString(),
                'due_date' => $l->due_date?->toDateString(),
                'status' => (string) $l->status,
            ])
            ->values();

        return [
            'base_loan_id' => $base->id,
            'top_ups' => $topUps,
        ];
    }

    public function topUpRefinancePreview(int $baseLoanId, User $viewer, ?float $requestedTotal = null): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $rules = SystemRule::firstOrFail();
        $loan = Loan::with('repayments')->findOrFail($baseLoanId);

        $minInst = (int) ($rules->min_installments_before_top_up ?? 3);
        $paidInst = (int) $loan->installmentsPaidCount();

        $enabled = (bool) ($rules->allow_loan_top_up ?? false);

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
        }

        $baseOutstanding = round((float) $loan->outstandingBalance(), 2);

        $tz = 'Africa/Kigali';
        $fy = $this->fyForNewLoan(Carbon::now($tz)->toDateString());

        $savingBase = (float) $this->ledger->savingsBaseForLoanLimit((int) $loan->user_id, $loan->beneficiary_id);
        $maxAllowed = $this->maxAllowedFromRules($rules, $savingBase);

        $activeLoans = $this->ownerLoanQuery((int) $loan->user_id, $loan->beneficiary_id)
            ->where('status', 'active')
            ->get();

        $exposureNow = round((float) $activeLoans->sum(fn($l) => (float) $l->outstandingBalance()), 2);

        $exposureAfterClose = round(max(0, $exposureNow - $baseOutstanding), 2);
        $headroomNewTotal = round(max(0, $maxAllowed - $exposureAfterClose), 2);

        $cashOutIfRequested = null;

        if ($requestedTotal !== null) {
            $requestedTotal = round((float) $requestedTotal, 2);
            $cashOutIfRequested = round(max(0, $requestedTotal - $baseOutstanding), 2);

            if ($requestedTotal <= $baseOutstanding) {
                $allowed = false;
                $reason = 'Requested amount must be greater than base outstanding balance.';
            } elseif ($requestedTotal > $headroomNewTotal + 0.0001) {
                $allowed = false;
                $reason = 'Requested amount exceeds allowed max based on headroom.';
            }
        }

        return [
            'allowed' => $allowed,
            'reason' => $reason,
            'data' => [
                'loan' => [
                    'id' => $loan->id,
                    'user_id' => $loan->user_id,
                    'beneficiary_id' => $loan->beneficiary_id,
                    'status' => (string) $loan->status,
                    'repayment_mode' => (string) ($loan->repayment_mode ?? 'once'),
                    'monthly_installment' => round((float) ($loan->monthly_installment ?? 0), 2),
                    'installments_paid' => $paidInst,
                    'outstanding_balance' => $baseOutstanding,
                    'due_date' => $loan->due_date ? Carbon::parse($loan->due_date)->toDateString() : null,
                ],
                'rules' => [
                    'allow_loan_top_up' => $enabled,
                    'min_installments_before_top_up' => $minInst,
                    'loan_limit_type' => (string) ($rules->loan_limit_type ?? ''),
                    'loan_limit_value' => (float) ($rules->loan_limit_value ?? 0),
                ],
                'eligibility' => [
                    'financial_year_rule_id_used' => (int) $fy->id,
                    'savings_base' => round((float) $savingBase, 2),
                    'max_allowed' => round((float) $maxAllowed, 2),
                    'active_loan_exposure' => $exposureNow,
                    'base_outstanding' => $baseOutstanding,
                    'headroom' => $headroomNewTotal,
                    'max_new_total_allowed' => $headroomNewTotal,
                    'cash_out_if_requested' => $cashOutIfRequested,
                ],
            ],
        ];
    }

    public function eligibilityPreview(
        int $userId,
        ?int $beneficiaryId,
        float $principal,
        User $viewer,
        float $interestRate,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $repaymentMode = 'once',
        ?int $durationMonths = 1
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        $isPrivileged = in_array($viewer->role, ['admin', 'treasurer'], true);
        $isSelfUser = (int) $viewer->id === (int) $userId;
        $isGuardian = false;

        if (!is_null($beneficiaryId)) {
            $beneficiary = \App\Models\Beneficiary::findOrFail($beneficiaryId);
            $isGuardian = (int) $viewer->id === (int) $beneficiary->guardian_user_id;
        }

        if (!$isPrivileged && !$isSelfUser && !$isGuardian) {
            throw new \Exception('Forbidden');
        }

        $rules = SystemRule::firstOrFail();

        $tz = 'Africa/Kigali';
        $fy = $this->fyForNewLoan(Carbon::now($tz)->toDateString());

        $hasActive = $this->ownerLoanQuery($userId, $beneficiaryId)
            ->where('status', 'active')
            ->exists();

        $minMonths = (int) ($rules->min_contribution_months ?? 0);

        $months = (int) $this->contributionService->contributedMonthsCountFromContributions(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
        );

        $savingBase = (float) $this->ledger->savingsBaseForLoanLimit($userId, $beneficiaryId);
        $maxAllowed = $this->maxAllowedFromRules($rules, $savingBase);

        $principal = round(max(0, $principal), 2);
        $shortfall = round(max(0, $principal - $maxAllowed), 2);

        $blockedReasons = [];

        if (!(bool) ($rules->allow_multiple_active_loans ?? false) && $hasActive) {
            $blockedReasons[] = 'Owner already has an active loan.';
        }

        if ($minMonths > 0 && $months < $minMonths) {
            $blockedReasons[] = "Owner must have at least {$minMonths} contribution months.";
        }

        $blocked = count($blockedReasons) > 0;

        [$interestRate, $interestBasis, $interestTermMonths] = $this->normalizeInterestInputs(
            $interestRate,
            $interestBasis,
            $interestTermMonths
        );

        $repaymentMode = $repaymentMode ?: 'once';
        $repaymentMode = in_array($repaymentMode, ['once', 'installment'], true) ? $repaymentMode : 'once';

        $durationMonths = max(1, (int) ($durationMonths ?? 1));
        $interestMonths = ($repaymentMode === 'installment') ? $durationMonths : 1;

        $interest = $this->computeInterest(
            principal: $principal,
            rate: $interestRate,
            basis: $interestBasis,
            durationMonths: $interestMonths,
            termMonths: $interestTermMonths
        );

        $interest = round((float) $interest, 2);
        $totalPayable = round($principal + $interest, 2);

        $monthlyInstallment = 0;
        if ($repaymentMode === 'installment') {
            $monthlyInstallment = round($principal / $durationMonths, 2);
        }

        return [
            'financial_year_rule_id_used' => (int) $fy->id,
            'savings_base' => round($savingBase, 2),
            'max_allowed' => round($maxAllowed, 2),
            'requested_principal' => $principal,
            'shortfall' => $shortfall,
            'requires_guarantor' => $shortfall > 0,
            'blocked' => $blocked,
            'blocked_reasons' => $blockedReasons,
            'months_contributed' => $months,
            'has_active_loan' => $hasActive,
            'pricing' => [
                'interest_rate' => $interestRate,
                'interest_basis' => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'duration_months' => ($repaymentMode === 'installment') ? $durationMonths : 1,
                'repayment_mode' => $repaymentMode,
                'interest' => $interest,
                'total_payable' => $totalPayable,
                'net_disbursed' => round(max(0, $principal - $interest), 2),
                'monthly_installment' => round($monthlyInstallment, 2),
            ],
        ];
    }

    public function disbursePreview(
        int $userId,
        ?int $beneficiaryId,
        float $principal,
        User $viewer,
        float $interestRate,
        string $interestBasis = 'per_year',
        ?int $interestTermMonths = null,
        ?string $repaymentMode = null,
        ?int $durationMonths = null
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $rules = SystemRule::firstOrFail();

        $tz = 'Africa/Kigali';
        $issuedDate = Carbon::now($tz)->toDateString();
        $fy = $this->fyForNewLoan($issuedDate);

        if (is_null($beneficiaryId)) {
            $member = User::query()
                ->select(['id', 'name', 'email', 'phone'])
                ->findOrFail($userId);

            $ownerMeta = [
                'owner_type' => 'user',
                'member' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'phone' => $member->phone,
                    'email' => $member->email,
                ],
            ];
        } else {
            $beneficiary = \App\Models\Beneficiary::query()
                ->with('guardian:id,name,email,phone')
                ->findOrFail($beneficiaryId);

            $ownerMeta = [
                'owner_type' => 'beneficiary',
                'beneficiary' => [
                    'id' => $beneficiary->id,
                    'name' => $beneficiary->name,
                    'relationship' => $beneficiary->relationship,
                ],
                'guardian' => $beneficiary->guardian ? [
                    'id' => $beneficiary->guardian->id,
                    'name' => $beneficiary->guardian->name,
                    'phone' => $beneficiary->guardian->phone,
                    'email' => $beneficiary->guardian->email,
                ] : null,
            ];
        }

        $requested = round(max(0, (float) $principal), 2);

        [$interestRate, $interestBasis, $interestTermMonths] = $this->normalizeInterestInputs(
            $interestRate,
            $interestBasis,
            $interestTermMonths
        );

        $activeLoans = $this->ownerLoanQuery($userId, $beneficiaryId)
            ->where('status', 'active')
            ->get();

        $hasActive = $activeLoans->isNotEmpty();
        $exposureNow = round((float) $activeLoans->sum(fn($l) => (float) $l->outstandingBalance()), 2);

        $minMonths = (int) ($rules->min_contribution_months ?? 0);

        $months = $minMonths > 0
            ? (int) $this->contributionService->contributedMonthsCountFromContributions($userId, $beneficiaryId)
            : 0;

        $savingBase = (float) $this->ledger->savingsBaseForLoanLimit($userId, $beneficiaryId);
        $maxAllowed = $this->maxAllowedFromRules($rules, $savingBase);

        $shortfall = round(max(0, $requested - $maxAllowed), 2);

        $mode = $repaymentMode ?: ($rules->loan_default_repayment_mode ?? 'once');
        $mode = in_array($mode, ['once', 'installment'], true) ? $mode : 'once';

        $duration = $durationMonths ?? max(1, (int) ($rules->loan_duration_months ?? 1));
        $duration = max(1, (int) $duration);

        $interestMonths = ($mode === 'installment') ? $duration : 1;

        $interest = $this->computeInterest(
            principal: $requested,
            rate: $interestRate,
            basis: $interestBasis,
            durationMonths: $interestMonths,
            termMonths: $interestTermMonths
        );

        $interest = round((float) $interest, 2);
        $totalPayable = round($requested + $interest, 2);
        $netDisbursed = round(max(0, $requested - $interest), 2);

        $monthlyInstallment = null;
        if ($mode === 'installment') {
            $monthlyInstallment = round($requested / $duration, 2);
        }

        $blockedReasons = [];

        if (!(bool) ($rules->allow_multiple_active_loans ?? false) && $hasActive) {
            $blockedReasons[] = 'Owner already has an active loan.';
        }

        if ($minMonths > 0 && $months < $minMonths) {
            $blockedReasons[] = "Owner must have at least {$minMonths} contribution months.";
        }

        $candidates = User::query()
            ->select(['id', 'name', 'email', 'phone'])
            ->when($userId, fn($q) => $q->where('id', '!=', $userId))
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
            ...$ownerMeta,
            'rules' => [
                'allow_multiple_active_loans' => (bool) ($rules->allow_multiple_active_loans ?? false),
                'min_contribution_months' => (int) ($rules->min_contribution_months ?? 0),
                'loan_limit_type' => (string) ($rules->loan_limit_type ?? ''),
                'loan_limit_value' => (float) ($rules->loan_limit_value ?? 0),
                'loan_default_repayment_mode' => (string) ($rules->loan_default_repayment_mode ?? 'once'),
                'loan_duration_months' => (int) ($rules->loan_duration_months ?? 1),
            ],
            'totals' => [
                'financial_year_rule_id_used' => (int) $fy->id,
                'savings_base' => round($savingBase, 2),
                'contribution_months' => (int) $months,
                'has_active_loan' => (bool) $hasActive,
                'active_loan_exposure' => $exposureNow,
            ],
            'eligibility' => [
                'max_allowed' => round($maxAllowed, 2),
                'requested_principal' => round($requested, 2),
                'shortfall' => round($shortfall, 2),
                'requires_guarantor' => $shortfall > 0,
                'blocked' => count($blockedReasons) > 0,
                'blocked_reasons' => $blockedReasons,
            ],
            'preview' => [
                'interest_rate' => round($interestRate, 2),
                'interest_basis' => $interestBasis,
                'interest_term_months' => $interestTermMonths,
                'interest' => round($interest, 2),
                'total_payable' => round($totalPayable, 2),
                'net_disbursed' => $netDisbursed,
                'repayment_mode' => $mode,
                'duration_months' => $duration,
                'monthly_installment' => $monthlyInstallment,
            ],
            'guarantor_candidates' => $candidates,
        ];
    }

    protected function allocateToInstallments(Loan $loan, float $amount, Carbon $paidAt, int $recordedBy): void
    {
        if (($loan->repayment_mode ?? 'once') !== 'installment') {
            return;
        }

        $remaining = round((float) $amount, 2);
        if ($remaining <= 0) {
            return;
        }

        $installments = $loan->installments()
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('installment_no')
            ->lockForUpdate()
            ->get();

        foreach ($installments as $inst) {
            if ($remaining <= 0) {
                break;
            }

            $due = round((float) ($inst->amount_due ?? 0), 2);
            $paid = round((float) ($inst->paid_amount ?? 0), 2);

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

            if (
                $inst->due_date &&
                $paidAt->gt(Carbon::parse($inst->due_date)) &&
                empty($inst->penalty_applied_at)
            ) {
                $penalty = $this->penaltyService->loanInstallmentLate(
                    userId: (int) $loan->user_id,
                    beneficiaryId: $loan->beneficiary_id,
                    loanId: $loan->id,
                    installmentId: $inst->id,
                    recordedBy: $recordedBy,
                    periodKey: $paidAt->format('Y-m'),
                    principalBase: (float) $need,
                    date: $paidAt->toDateString()
                );

                $inst->penalty_applied_at = $paidAt;
                $inst->penalty_id = $penalty->id ?? null;
            }

            $pay = round(min($need, $remaining), 2);

            $inst->paid_amount = round($paid + $pay, 2);

            if ($inst->paid_amount >= $due) {
                $inst->status = 'paid';
                $inst->paid_date = $inst->paid_date ?: $paidAt->toDateString();
            } else {
                $inst->status = 'partial';
            }

            $inst->save();
            $remaining = round($remaining - $pay, 2);
        }
    }

    public function repayPreview(int $loanId, User $viewer): array
    {
        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            throw new \Exception('Forbidden');
        }

        $tz = 'Africa/Kigali';

        $loan = Loan::with([
            'user:id,name,phone,email',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,phone,email',
            'migrationSnapshot',
        ])->findOrFail($loanId);

        $principalOutstanding = round((float) $loan->outstandingPrincipal(), 2);
        $interestOutstanding = round((float) $loan->outstandingInterest(), 2);
        $outstandingTotal = round($principalOutstanding + $interestOutstanding, 2);

        $principalOutstanding = max(0, $principalOutstanding);
        $interestOutstanding = max(0, $interestOutstanding);
        $outstandingTotal = max(0, $outstandingTotal);

        $nextInstallment = null;
        $displayDueDate = $loan->due_date ? Carbon::parse($loan->due_date, $tz)->toDateString() : null;

        if (($loan->repayment_mode ?? 'once') === 'installment') {
            $inst = LoanInstallment::query()
                ->where('loan_id', $loan->id)
                ->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('installment_no')
                ->first();

            if ($inst) {
                $amountDue = round((float) $inst->amount_due, 2);
                $paidAmt = round((float) ($inst->paid_amount ?? 0), 2);
                $remain = round(max(0, $amountDue - $paidAmt), 2);

                $nextInstallment = [
                    'id' => (int) $inst->id,
                    'installment_no' => (int) $inst->installment_no,
                    'due_date' => $inst->due_date ? Carbon::parse($inst->due_date, $tz)->toDateString() : null,
                    'amount_due' => $amountDue,
                    'paid_amount' => $paidAmt,
                    'remaining' => $remain,
                    'status' => (string) $inst->status,
                ];

                $displayDueDate = $nextInstallment['due_date'];
            }
        }

        $fy = $this->fyForLoan($loan);

        $ownerMeta = is_null($loan->beneficiary_id)
            ? [
                'owner_type' => 'user',
                'member' => [
                    'id' => $loan->user_id,
                    'name' => $loan->user?->name,
                    'phone' => $loan->user?->phone,
                    'email' => $loan->user?->email,
                ],
            ]
            : [
                'owner_type' => 'beneficiary',
                'beneficiary' => [
                    'id' => $loan->beneficiary_id,
                    'name' => $loan->beneficiary?->name,
                    'relationship' => $loan->beneficiary?->relationship,
                ],
                'guardian' => $loan->beneficiary?->guardian ? [
                    'id' => $loan->beneficiary->guardian->id,
                    'name' => $loan->beneficiary->guardian->name,
                    'phone' => $loan->beneficiary->guardian->phone,
                    'email' => $loan->beneficiary->guardian->email,
                ] : null,
            ];

        return [
            'loan' => [
                'id' => $loan->id,
                ...$ownerMeta,
                'principal' => round((float) $loan->principal, 2),
                'total_payable' => round((float) $loan->total_payable, 2),
                'is_migrated' => (bool) $loan->is_migrated,
                'repayment_mode' => (string) ($loan->repayment_mode ?? 'once'),
                'loan_due_date' => $loan->due_date ? Carbon::parse($loan->due_date, $tz)->toDateString() : null,
                'issued_date' => $loan->issued_date ? Carbon::parse($loan->issued_date, $tz)->toDateString() : null,
                'financial_year_rule_id_used' => (int) $fy->id,

                'migration_snapshot' => $loan->is_migrated && $loan->migrationSnapshot ? [
                    'migration_date' => $loan->migrationSnapshot->migration_date
                        ? Carbon::parse($loan->migrationSnapshot->migration_date, $tz)->toDateString()
                        : null,
                    'original_principal' => round((float) $loan->migrationSnapshot->original_principal, 2),
                    'original_total_payable' => !is_null($loan->migrationSnapshot->original_total_payable)
                        ? round((float) $loan->migrationSnapshot->original_total_payable, 2)
                        : null,
                    'principal_paid_before_migration' => round((float) $loan->migrationSnapshot->principal_paid_before_migration, 2),
                    'interest_paid_before_migration' => round((float) $loan->migrationSnapshot->interest_paid_before_migration, 2),
                    'opening_outstanding_principal' => round((float) $loan->migrationSnapshot->outstanding_principal, 2),
                    'opening_outstanding_interest' => round((float) $loan->migrationSnapshot->outstanding_interest, 2),
                ] : null,
            ],
            'outstanding' => [
                'total' => $outstandingTotal,
                'principal' => $principalOutstanding,
                'interest' => $interestOutstanding,
            ],
            'next_installment' => $nextInstallment,
            'display_due_date' => $displayDueDate,
        ];
    }

    public function computeInterest(float $principal, float $rate, string $basis, int $durationMonths, ?int $termMonths = null): float
    {
        if ($principal <= 0 || $rate <= 0 || $durationMonths <= 0) {
            return 0;
        }

        $basis = strtolower($basis);

        if ($basis === 'per_month') {
            return round($principal * ($rate / 100) * $durationMonths, 2);
        }

        if ($basis === 'per_year') {
            $monthlyRate = $rate / 12;
            return round($principal * ($monthlyRate / 100) * $durationMonths, 2);
        }

        if ($basis === 'per_term') {
            $termMonths = max(1, (int) ($termMonths ?? 1));
            $terms = $durationMonths / $termMonths;
            return round($principal * ($rate / 100) * $terms, 2);
        }

        return round($principal * ($rate / 100), 2);
    }

    public function installmentDueForPeriod(int $userId, ?int $beneficiaryId, string $periodKey): float
    {
        $this->validateOwner($userId, $beneficiaryId);

        $tz = 'Africa/Kigali';

        $start = Carbon::createFromFormat('Y-m-d', $periodKey . '-01', $tz)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $loans = $this->ownerLoanQuery($userId, $beneficiaryId)
            ->where('status', 'active')
            ->get(['id', 'user_id', 'beneficiary_id', 'repayment_mode', 'due_date', 'total_payable', 'principal', 'interest_amount']);

        if ($loans->isEmpty()) {
            return 0.0;
        }

        $installmentLoanIds = $loans->where('repayment_mode', 'installment')->pluck('id')->values();

        $due = 0.0;

        if ($installmentLoanIds->isNotEmpty()) {
            $insts = LoanInstallment::query()
                ->whereIn('loan_id', $installmentLoanIds)
                ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('status', ['unpaid', 'partial'])
                ->get(['loan_id', 'amount_due', 'paid_amount']);

            foreach ($insts as $inst) {
                $amountDue = round((float) ($inst->amount_due ?? 0), 2);
                $paidAmt = round((float) ($inst->paid_amount ?? 0), 2);
                $remain = round(max(0, $amountDue - $paidAmt), 2);
                $due += $remain;
            }
        }

        $onceLoans = $loans->filter(fn($l) => ($l->repayment_mode ?? 'once') === 'once');

        foreach ($onceLoans as $loan) {
            if (empty($loan->due_date)) {
                continue;
            }

            $dueDate = Carbon::parse($loan->due_date, $tz);
            if ($dueDate->lt($start) || $dueDate->gt($end)) {
                continue;
            }

            $out = round((float) $loan->outstandingBalance(), 2);
            if ($out > 0) {
                $due += $out;
            }
        }

        return round($due, 2);
    }

    public function repayFromPayroll(
        int $userId,
        ?int $beneficiaryId,
        float $amount,
        string $paidDate,
        int $recordedBy,
        string $periodKey
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        $tz = 'Africa/Kigali';

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return ['paid_total' => 0.0, 'items' => []];
        }

        $paidAt = Carbon::parse($paidDate, $tz);

        $start = Carbon::createFromFormat('Y-m-d', $periodKey . '-01', $tz)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $items = [];

        $installments = LoanInstallment::query()
            ->whereHas('loan', function ($q) use ($userId, $beneficiaryId) {
                $q->where('user_id', $userId)
                    ->when(is_null($beneficiaryId), fn($qq) => $qq->whereNull('beneficiary_id'))
                    ->when(!is_null($beneficiaryId), fn($qq) => $qq->where('beneficiary_id', $beneficiaryId))
                    ->where('status', 'active');
            })
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('due_date')
            ->orderBy('installment_no')
            ->get();

        foreach ($installments as $inst) {
            $dueAmt = round((float) ($inst->amount_due ?? 0), 2);
            $paidAmt = round((float) ($inst->paid_amount ?? 0), 2);
            $remain = round(max(0, $dueAmt - $paidAmt), 2);

            if ($remain <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'installment',
                'loan_id' => (int) $inst->loan_id,
                'installment_id' => (int) $inst->id,
                'due_date' => $inst->due_date,
                'amount_due' => $remain,
            ];
        }

        $onceLoans = $this->ownerLoanQuery($userId, $beneficiaryId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('repayment_mode')->orWhere('repayment_mode', 'once');
            })
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('due_date')
            ->get();

        foreach ($onceLoans as $loan) {
            $out = round((float) $loan->outstandingBalance(), 2);
            if ($out <= 0) {
                continue;
            }

            $items[] = [
                'type' => 'once_loan',
                'loan_id' => (int) $loan->id,
                'installment_id' => null,
                'due_date' => $loan->due_date,
                'amount_due' => $out,
            ];
        }

        return DB::transaction(function () use ($items, $amount, $paidAt, $recordedBy) {
            $remaining = round($amount, 2);

            $breakdown = [];
            $paidTotal = 0.0;

            foreach ($items as $it) {
                if ($remaining <= 0) {
                    break;
                }

                $need = round((float) $it['amount_due'], 2);
                if ($need <= 0) {
                    continue;
                }

                $pay = round(min($need, $remaining), 2);
                if ($pay <= 0) {
                    continue;
                }

                $res = $this->repayWithAutoSplit(
                    loanId: (int) $it['loan_id'],
                    amount: $pay,
                    paidDate: $paidAt->toDateString(),
                    recordedBy: $recordedBy
                );

                $breakdown[] = [
                    'loan_id' => (int) $it['loan_id'],
                    'installment_id' => $it['installment_id'],
                    'paid' => $pay,
                    'message' => $res['message'] ?? null,
                    'repayment_id' => $res['repayment']['id'] ?? null,
                    'financial_year_rule_id_used' => $res['financial_year_rule_id_used'] ?? null,
                ];

                $remaining = round($remaining - $pay, 2);
                $paidTotal = round($paidTotal + $pay, 2);
            }

            return [
                'paid_total' => $paidTotal,
                'remaining_unallocated' => $remaining,
                'items' => $breakdown,
            ];
        });
    }

    private function recordUpfrontInterestDeduction(Loan $loan, float $interestAmount, int $recordedBy, string $issuedDateYmd): void
    {
        $interestAmount = round((float) $interestAmount, 2);
        if ($interestAmount <= 0) {
            return;
        }

        $tz = 'Africa/Kigali';

        LoanRepayment::create([
            'loan_id' => $loan->id,
            'amount' => $interestAmount,
            'interest_component' => $interestAmount,
            'principal_component' => 0,
            'repayment_date' => Carbon::parse($issuedDateYmd, $tz),
            'recorded_by' => $recordedBy,
        ]);

        $this->ledger->record(
            type: 'loan_interest_deducted',
            debit: 0,
            credit: $interestAmount,
            userId: (int) $loan->user_id,
            beneficiaryId: $loan->beneficiary_id,
            reference: 'Upfront interest deducted for Loan ID ' . $loan->id,
            createdBy: $recordedBy,
            sourceType: 'loan',
            sourceId: $loan->id
        );
    }

    private function normalizeInterestInputs(?float $interestRate, string $interestBasis, ?int $interestTermMonths): array
    {
        $interestRate = round((float) $interestRate, 2);
        if ($interestRate <= 0) {
            throw new \Exception('Interest rate is required and must be greater than 0.');
        }

        $interestBasis = strtolower(trim($interestBasis));
        if (!in_array($interestBasis, ['per_month', 'per_year', 'per_term'], true)) {
            throw new \Exception('Invalid interest basis. Use per_month, per_year, or per_term.');
        }

        if ($interestBasis === 'per_term') {
            $interestTermMonths = (int) ($interestTermMonths ?? 0);
            if ($interestTermMonths <= 0) {
                throw new \Exception('interest_term_months is required when interest_basis is per_term.');
            }
        } else {
            $interestTermMonths = null;
        }

        return [$interestRate, $interestBasis, $interestTermMonths];
    }

    private function maxAllowedFromRules(SystemRule $rules, float $savingBase): float
    {
        $maxAllowed = match ($rules->loan_limit_type) {
            'multiple' => $savingBase * (float) ($rules->loan_limit_value ?? 3),
            'equal'    => $savingBase,
            'fixed'    => (float) ($rules->loan_limit_value ?? 0),
            default    => $savingBase * 3,
        };

        return round(max(0, (float) $maxAllowed), 2);
    }
    public function adjustLoan(
    int $loanId,
    float $amount,
    string $reason,
    int $recordedBy
): Loan {
    $amount = round((float) $amount, 2);
    $reason = trim($reason);

    if ($amount == 0.0) {
        throw new InvalidArgumentException('Adjustment amount cannot be zero.');
    }

    if ($reason === '') {
        throw new InvalidArgumentException('Adjustment reason is required.');
    }

    return DB::transaction(function () use ($loanId, $amount, $reason, $recordedBy) {
        $tz = 'Africa/Kigali';

        $loan = Loan::query()
            ->with(['repayments', 'installments'])
            ->lockForUpdate()
            ->findOrFail($loanId);

        if ($loan->status !== 'active') {
            throw new InvalidArgumentException('Only active loans can be adjusted.');
        }

        $repaymentsCount = $loan->repayments->count();
        if ($repaymentsCount > 0) {
            throw new InvalidArgumentException(
                'Loan cannot be adjusted after repayments have started. Use reversal/restructure flow.'
            );
        }

        $beforePrincipal = round((float) $loan->principal, 2);
        $afterPrincipal = round($beforePrincipal + $amount, 2);

        if ($afterPrincipal <= 0) {
            throw new InvalidArgumentException('Loan principal must remain greater than zero after adjustment.');
        }

        $mode = (string) ($loan->repayment_mode ?? 'once');
        $duration = max(1, (int) ($loan->duration_months ?? 1));

        $interestRate = round((float) ($loan->interest_rate ?? 0), 2);
        $interestBasis = (string) ($loan->interest_basis ?? 'per_year');
        $interestTermMonths = $loan->interest_term_months ? (int) $loan->interest_term_months : null;

        $interestMonths = ($mode === 'installment') ? $duration : 1;

        $newInterestAmount = round((float) $this->computeInterest(
            principal: $afterPrincipal,
            rate: $interestRate,
            basis: $interestBasis,
            durationMonths: $interestMonths,
            termMonths: $interestTermMonths
        ), 2);

        $newTotalPayable = round($afterPrincipal + $newInterestAmount, 2);
        $newNetDisbursed = round($afterPrincipal - $newInterestAmount, 2);

        if ($newNetDisbursed < 0) {
            throw new InvalidArgumentException('Interest is greater than principal after adjustment.');
        }

        $loan->principal = $afterPrincipal;
        $loan->interest_amount = $newInterestAmount;
        $loan->total_payable = $newTotalPayable;
        $loan->approved_by = $loan->approved_by ?: $recordedBy;
        $loan->rate_set_by = $recordedBy;
        $loan->rate_set_at = now($tz);

        if ($mode === 'installment') {
            $remainingOutstanding = round($newTotalPayable - $newInterestAmount, 2);
            $monthlyInstallment = round($remainingOutstanding / $duration, 2);
            $loan->monthly_installment = $monthlyInstallment;
        }

        $loan->save();

        if ($mode === 'installment') {
            $installments = LoanInstallment::query()
                ->where('loan_id', $loan->id)
                ->orderBy('installment_no')
                ->lockForUpdate()
                ->get();

            if ($installments->isNotEmpty()) {
                $remainingOutstanding = round($newTotalPayable - $newInterestAmount, 2);
                $baseAmt = round($remainingOutstanding / $duration, 2);
                $sumFirst = round($baseAmt * ($duration - 1), 2);
                $lastAmt = round($remainingOutstanding - $sumFirst, 2);

                foreach ($installments as $inst) {
                    $instNo = (int) $inst->installment_no;
                    $inst->amount_due = ($instNo === $duration) ? $lastAmt : $baseAmt;
                    $inst->save();
                }
            }
        }

        $tx = $this->ledger->record(
            type: 'loan_adjustment',
            debit: $amount > 0 ? $amount : 0,
            credit: $amount < 0 ? abs($amount) : 0,
            userId: (int) $loan->user_id,
            reference: "Loan adjustment for Loan ID {$loan->id} — {$reason}",
            createdBy: $recordedBy,
            sourceType: 'loan_adjustment',
            sourceId: (int) $loan->id,
            beneficiaryId: $loan->beneficiary_id
        );

        \App\Models\Adjustment::create([
            'adjustable_type' => $loan->getMorphClass(),
            'adjustable_id'   => $loan->id,
            'user_id'         => (int) $loan->user_id,
            'beneficiary_id'  => $loan->beneficiary_id,
            'as_of_period'    => $loan->issued_date
                ? Carbon::parse($loan->issued_date, $tz)->format('Y-m')
                : Carbon::now($tz)->format('Y-m'),
            'amount'          => $amount,
            'reason'          => $reason,
            'transaction_id'  => $tx->id,
            'created_by'      => $recordedBy,
        ]);

        return $loan->fresh([
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
            'installments',
            'repayments',
        ]);
    });
}
}
