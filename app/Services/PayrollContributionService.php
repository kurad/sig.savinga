<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Models\SystemRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollContributionService
{
    public function __construct(
        protected ContributionService $contributionService,
        protected LoanService $loanService,
        protected PenaltyService $penaltyService,
        protected CommitmentService $commitmentService,
    ) {}

    /**
     * CSV format (header required):
     * payroll_no,total_amount
     *
     * Returns aggregated rows by payroll_no:
     * [
     *   ['payroll_no' => 'EMP001', 'total_amount' => 120000],
     *   ...
     * ]
     */
    public function parseCsv($file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) return [];

        $header = fgetcsv($handle);
        if (!$header) return [];

        $map = [];
        foreach ($header as $i => $col) {
            $map[strtolower(trim($col))] = $i;
        }

        if (!isset($map['payroll_no']) || !isset($map['total_amount'])) {
            fclose($handle);
            return [];
        }

        // ✅ aggregate duplicates
        $agg = []; // payroll_no => total_amount

        while (($line = fgetcsv($handle)) !== false) {
            $payrollNo = trim((string)($line[$map['payroll_no']] ?? ''));
            $totalRaw  = $line[$map['total_amount']] ?? 0;

            if ($payrollNo === '') continue;

            $total = (float) $totalRaw;

            if (!isset($agg[$payrollNo])) $agg[$payrollNo] = 0.0;
            $agg[$payrollNo] += $total;
        }

        fclose($handle);

        $rows = [];
        foreach ($agg as $payrollNo => $totalAmount) {
            $rows[] = [
                'payroll_no' => $payrollNo,
                'total_amount' => (float) $totalAmount,
            ];
        }

        return $rows;
    }

    public function preview(string $periodKey, string $paidDate, array $rows, int $viewerId): array
    {
        $rules = SystemRule::first();
        $expectedDate = $this->expectedDateFromRules($rules, $periodKey);

        $totals = [
            'rows' => count($rows),
            'matched' => 0,
            'unmatched' => 0,
            'invalid' => 0,
            'underpaid' => 0,
            'already_processed' => 0,
            'sum_total' => 0.0,
            'sum_penalty' => 0.0,
            'sum_loan' => 0.0,
            'sum_contribution' => 0.0,
        ];

        // ✅ bulk fetch users
        $payrollNos = collect($rows)->pluck('payroll_no')->filter()->unique()->values();
        $usersByPayroll = User::query()
            ->where('payment_mode', 'payroll')
            ->whereIn('payroll_no', $payrollNos)
            ->get()
            ->keyBy('payroll_no');

        $resultRows = [];

        foreach ($rows as $r) {
            $total = (float)($r['total_amount'] ?? 0);
            $totals['sum_total'] += $total;

            $payrollNo = (string)($r['payroll_no'] ?? '');

            if ($total <= 0) {
                $totals['invalid']++;
                $resultRows[] = [
                    'payroll_no' => $payrollNo,
                    'total_amount' => $total,
                    'status' => 'invalid',
                    'message' => 'Total amount must be greater than 0.',
                ];
                continue;
            }

            $user = $usersByPayroll->get($payrollNo);

            if (!$user) {
                $totals['unmatched']++;
                $resultRows[] = [
                    'payroll_no' => $payrollNo,
                    'total_amount' => $total,
                    'status' => 'unmatched',
                    'message' => 'No payroll member found for this payroll_no.',
                ];
                continue;
            }

            // ✅ optional anti-duplicate hook (implement later)
            if ($this->alreadyProcessedPayrollForPeriod($user->id, $periodKey)) {
                $totals['already_processed']++;
                $resultRows[] = [
                    'user' => ['id' => $user->id, 'name' => $user->name, 'payroll_no' => $user->payroll_no],
                    'period' => $periodKey,
                    'paid_date' => $paidDate,
                    'expected_date' => $expectedDate,
                    'total_amount' => $total,
                    'status' => 'already_processed',
                    'message' => 'Payroll already processed for this member and period (skipped).',
                ];
                continue;
            }

            $penaltyDue = (float) $this->penaltyService->outstandingAmount($user->id);
            $loanDue    = (float) $this->loanService->installmentDueForPeriod($user->id, $periodKey);

            $requiredMinimum = $penaltyDue + $loanDue;
            $shortfall = max(0.0, $requiredMinimum - $total);

            $remaining = $total;

            $penaltyPaid = min($remaining, $penaltyDue);
            $remaining -= $penaltyPaid;

            $loanPaid = min($remaining, $loanDue);
            $remaining -= $loanPaid;

            $contribution = max(0.0, $remaining);

            $isUnderpaid = $total + 0.00001 < $requiredMinimum;
            if ($isUnderpaid) $totals['underpaid']++;
            $totals['matched']++;

            $totals['sum_penalty'] += $penaltyPaid;
            $totals['sum_loan'] += $loanPaid;
            $totals['sum_contribution'] += $contribution;

            $resultRows[] = [
                'user' => ['id' => $user->id, 'name' => $user->name, 'payroll_no' => $user->payroll_no],
                'period' => $periodKey,
                'paid_date' => $paidDate,
                'expected_date' => $expectedDate,

                'total_amount' => $total,

                'penalty_due' => $penaltyDue,
                'loan_due' => $loanDue,
                'required_minimum' => $requiredMinimum,
                'shortfall' => $shortfall,

                'penalty_paid' => $penaltyPaid,
                'loan_paid' => $loanPaid,
                'contribution_amount' => $contribution,

                'status' => $isUnderpaid ? 'underpaid' : 'ok',
                'message' => $isUnderpaid ? 'Total is less than penalty+loan due.' : null,
            ];
        }

        return [
            'period' => $periodKey,
            'paid_date' => $paidDate,
            'expected_date_default' => $expectedDate,
            'totals' => $totals,
            'rows' => $resultRows,
        ];
    }

    public function commit(string $periodKey, string $paidDate, ?string $expectedDate, array $rows, int $recordedBy): array
    {
        $rules = SystemRule::first();
        $expectedDate ??= $this->expectedDateFromRules($rules, $periodKey);

        // policy
        $MIN_CONTRIB = 10000.0;
        $SMALL_REMAINDER_RATE = 0.05; // 5%

        $results = [
            'period' => $periodKey,
            'paid_date' => $paidDate,
            'expected_date' => $expectedDate,
            'ok' => [],
            'errors' => [],
            'skipped' => [],
        ];

        // ✅ bulk fetch users (faster + consistent)
        $payrollNos = collect($rows)->pluck('payroll_no')->filter()->unique()->values();

        $usersByPayroll = User::query()
            ->where('payment_mode', 'payroll')
            ->whereIn('payroll_no', $payrollNos)
            ->get()
            ->keyBy('payroll_no');

        DB::beginTransaction();
        try {
            foreach ($rows as $r) {
                $payrollNo = trim((string)($r['payroll_no'] ?? ''));
                $total = round((float)($r['total_amount'] ?? 0), 2);

                if ($payrollNo === '' || $total <= 0) {
                    $results['skipped'][] = [
                        'payroll_no' => $payrollNo ?: null,
                        'reason' => 'invalid_row',
                        'message' => 'payroll_no missing or total_amount <= 0',
                    ];
                    continue;
                }

                /** @var \App\Models\User|null $user */
                $user = $usersByPayroll->get($payrollNo);

                if (!$user) {
                    $results['skipped'][] = [
                        'payroll_no' => $payrollNo,
                        'reason' => 'unmatched_payroll_no',
                    ];
                    continue;
                }

                // prevent double-posting for the same member + period
                if ($this->alreadyProcessedPayrollForPeriod($user->id, $periodKey)) {
                    $results['skipped'][] = [
                        'user_id' => $user->id,
                        'payroll_no' => $payrollNo,
                        'reason' => 'already_processed',
                    ];
                    continue;
                }

                // dues
                $penaltyDue = round((float) $this->penaltyService->outstandingAmount($user->id), 2);
                $loanDue    = round((float) $this->loanService->installmentDueForPeriod($user->id, $periodKey), 2);

                $requiredMinimum = round($penaltyDue + $loanDue, 2);

                // strict: payroll must at least cover penalty+loan due
                if ($total + 0.00001 < $requiredMinimum) {
                    $results['errors'][] = [
                        'user_id' => $user->id,
                        'payroll_no' => $payrollNo,
                        'total' => $total,
                        'required_minimum' => $requiredMinimum,
                        'shortfall' => round($requiredMinimum - $total, 2),
                        'message' => 'Underpaid: total is less than penalty+loan due. Not posted.',
                    ];
                    continue;
                }

                $remaining = $total;

                // allocate: penalty -> loan -> remainder
                $penaltyPaid = round(min($remaining, $penaltyDue), 2);
                $remaining   = round($remaining - $penaltyPaid, 2);

                $loanPaid = round(min($remaining, $loanDue), 2);
                $remaining = round($remaining - $loanPaid, 2);

                $remainder = round(max(0, $remaining), 2);

                // ✅ enforce "min contribution" rule for payroll:
                // If remainder is below 10k, we still record it, but apply a 5% penalty on the remainder.
                // Penalty is taken from the remainder (so totals stay balanced).
                $extraPenalty = 0.0;
                $contribution = 0.0;

                if ($remainder > 0 && $remainder < $MIN_CONTRIB) {
                    $extraPenalty = round($remainder * $SMALL_REMAINDER_RATE, 2);
                    $contribution = round(max(0, $remainder - $extraPenalty), 2);
                } else {
                    $contribution = $remainder;
                }

                // -------------------------
                // POST TRANSACTIONS
                // -------------------------

                // 1) settle penalty (existing unpaid penalties)
                if ($penaltyPaid > 0) {
                    $this->penaltyService->settleFromPayroll($user->id, $penaltyPaid, $paidDate, $recordedBy);
                }

                // 2) loan repayment
                if ($loanPaid > 0) {
                    $this->loanService->repayFromPayroll($user->id, $loanPaid, $paidDate, $recordedBy, $periodKey);
                }

                // 3) ensure commitment exists (Option B) — but payroll can still post even if missing (Option A)
                // If you don't have setForPeriod(), implement it in CommitmentService.
                $commitment = $this->commitmentService->activeForPeriod($user->id, $periodKey);

                if (!$commitment) {
                    // Option B: auto-create a cycle commitment that covers $periodKey
                    // You need an anchor + cycle months. Best source: SystemRule.
                    $cycleMonths = (int)($rules->contribution_cycle_months ?? 1);
                    $anchor      = (string)($rules->contribution_cycle_anchor_period ?? $periodKey); // e.g. "2026-01"

                    // choose a default amount:
                    // - safest: MIN_CONTRIB (10k)
                    // - or last active commitment amount if it exists
                    $last = $this->commitmentService->activeForPeriod($user->id, $this->prevPeriodKey($periodKey));
                    $defaultAmount = $last ? (float)$last->amount : $MIN_CONTRIB;

                    [$cycleStart, $cycleEnd] = $this->commitmentService->cycleWindow($periodKey, $anchor, $cycleMonths);

                    $commitment = $this->commitmentService->setForCycle(
                        userId: $user->id,
                        amount: $defaultAmount,
                        cycleStart: $cycleStart,
                        cycleEnd: $cycleEnd,
                        cycleMonths: $cycleMonths,
                        createdBy: $recordedBy // ✅ correct name (NOT setBy)
                    );
                }

                // Best effort: if you have CommitmentService injected in this Payroll service, use it directly instead.
                // if (!$commitment) { $this->commitmentService->setForPeriod($user->id, $periodKey, max($MIN_CONTRIB, $someDefault), $recordedBy); }

                // 4) contribution remainder
                $contribRes = null;
                $contribId = null;

                if ($contribution > 0) {
                    // IMPORTANT:
                    // - strictCommitment:false => does not throw when commitment missing (Option A)
                    // - bypassMin:true => allows posting < contribution_min_amount for payroll remainder cases
                    $contribRes = $this->contributionService->record(
                        memberId: $user->id,
                        amount: $contribution,
                        expectedDate: $expectedDate,
                        paidDate: $paidDate,
                        recordedBy: $recordedBy,
                        period: $periodKey,
                        strictCommitment: false,
                        bypassMin: true
                    );

                    $contribId = $contribRes['start']?->id ?? null;
                }

                // 5) extra 5% penalty on small remainder (already deducted via payroll)
                if ($extraPenalty > 0) {
                    $this->penaltyService->createAndMarkPaid(
                        memberId: $user->id,
                        sourceType: 'contribution',
                        sourceId: $contribId,
                        amount: $extraPenalty,
                        reason: 'Small remainder payroll penalty (5%)',
                        recordedBy: $recordedBy,
                        paidDate: $paidDate
                    );
                }

                $results['ok'][] = [
                    'user_id' => $user->id,
                    'payroll_no' => $payrollNo,
                    'total' => $total,
                    'penalty_due' => $penaltyDue,
                    'loan_due' => $loanDue,

                    'penalty_paid' => $penaltyPaid,
                    'loan_paid' => $loanPaid,

                    'remainder' => $remainder,
                    'extra_penalty' => $extraPenalty,
                    'contribution' => $contribution,

                    'contribution_id' => $contribId,
                ];
            }

            DB::commit();
            return $results;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * ✅ Implement later (recommended) to avoid duplicate commits.
     * For now returns false.
     */
    private function alreadyProcessedPayrollForPeriod(int $userId, string $periodKey): bool
    {
        // If a contribution exists for this period and user => assume payroll already posted.
        $hasContribution = Contribution::query()
            ->where('user_id', $userId)
            ->where('period_key', $periodKey)
            ->exists();

        if ($hasContribution) return true;

        // Optional extra guard: any loan repayment created in that month
        // (helps if the payroll deduction was only loan repayment and no contribution remainder)
        $start = Carbon::createFromFormat('Y-m-d', $periodKey . '-01')->startOfMonth()->toDateString();
        $end   = Carbon::createFromFormat('Y-m-d', $periodKey . '-01')->endOfMonth()->toDateString();

        $hasRepayment = LoanRepayment::query()
            ->whereBetween('repayment_date', [$start, $end])
            ->whereHas('loan', fn($q) => $q->where('user_id', $userId))
            ->exists();

        return $hasRepayment;
    }

    private function expectedDateFromRules(?SystemRule $rules, string $periodKey): string
    {
        $dueDay = (int) ($rules->contribution_due_day ?? 25);

        $first = Carbon::createFromFormat('Y-m-d', $periodKey . '-01')->startOfDay();
        $lastDay = $first->copy()->endOfMonth()->day;

        return $first->copy()->day(min($dueDay, $lastDay))->format('Y-m-d');
    }

    private function prevPeriodKey(string $periodKey): string
    {
        $d = Carbon::createFromFormat('Y-m', $periodKey)->startOfMonth()->subMonth();
        return $d->format('Y-m');
    }
}
