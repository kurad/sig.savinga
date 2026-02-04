<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Penalty;
use App\Models\SystemRule;
use App\Models\GraceWindow;
use Illuminate\Support\Facades\DB;

class PenaltyService
{
    public function __construct(
        protected TransactionService $ledger
    ) {}

    protected function isLateContributionPenaltyExempt(string $periodKey, Carbon $paidDate): bool
    {
        return GraceWindow::query()
            ->where('period_key', $periodKey)
            ->whereDate('start_date', '<=', $paidDate->toDateString())
            ->whereDate('end_date', '>=', $paidDate->toDateString())
            ->exists();
    }

    /**
     * Late contribution penalty
     */
    public function contributionLate(int $memberId, int $contributionId, int $recordedBy, string $periodKey, string $paidDate): ?Penalty
    {
        $paid = Carbon::parse($paidDate);
        if ($this->isLateContributionPenaltyExempt($periodKey, $paid)) {
            return null;
        }

        return $this->apply(
            memberId: $memberId,
            sourceType: 'contribution',
            sourceId: $contributionId,
            amount: (float) SystemRule::firstOrFail()->late_contribution_penalty,
            reason: 'Late contribution',
            recordedBy: $recordedBy
        );
    }

    /**
     * Missed contribution penalty
     */
    public function contributionMissed(int $memberId, int $contributionId, int $recordedBy): ?Penalty
    {
        return $this->apply(
            memberId: $memberId,
            sourceType: 'contribution',
            sourceId: $contributionId,
            amount: (float) SystemRule::firstOrFail()->missed_contribution_penalty,
            reason: 'Missed contribution',
            recordedBy: $recordedBy
        );
    }

    /**
     * Late loan repayment penalty (legacy / loan-level)
     */
    public function loanLate(int $memberId, int $loanId, int $recordedBy): ?Penalty
    {
        return $this->apply(
            memberId: $memberId,
            sourceType: 'loan',
            sourceId: $loanId,
            amount: (float) SystemRule::firstOrFail()->late_loan_penalty,
            reason: 'Late loan repayment',
            recordedBy: $recordedBy
        );
    }

    /**
     * ✅ Late INSTALLMENT penalty (accurate monthly schedule)
     *
     * Uses configurable rules:
     * - system_rules.loan_installment_penalty_type: percent_of_installment | fixed
     * - system_rules.loan_installment_penalty_value: e.g. 2.5 (%), or fixed RWF
     *
     * Fallback:
     * - if not configured, uses system_rules.late_loan_penalty (your current fixed penalty)
     */
    public function loanInstallmentLate(
        int $memberId,
        int $loanId,
        int $installmentId,
        int $recordedBy,
        ?float $baseAmount = null,
    ): ?Penalty {
        $rules = SystemRule::firstOrFail();
        $fixed = (float)($rules->late_loan_penalty ?? 0);
        $percent = (float)($rules->late_loan_penalty_percent ?? 0);


        $amount = 0;

        if ($percent > 0 && $baseAmount !== null) {
            $amount = round($baseAmount * ($percent / 100), 2);
        } else {
            $amount = $fixed;
        }
        return $this->apply(
            memberId: $memberId,
            sourceType: 'loan_installment',
            sourceId: $installmentId,
            amount: (float) $amount,
            reason: "Late loan installment",
            recordedBy: $recordedBy,
        );
    }

    /**
     * Manual penalty (admin-imposed)
     */
    public function manual(int $memberId, float $amount, string $reason, int $recordedBy, ?string $date = null): ?Penalty
    {
        return $this->apply(
            memberId: $memberId,
            sourceType: 'manual',
            sourceId: null,
            amount: $amount,
            reason: $reason,
            recordedBy: $recordedBy,
            date: $date
        );
    }

    /**
     * Mark penalty as PAID (for API)
     */
    public function markPaid(int $penaltyId, int $resolvedBy, ?string $date = null): Penalty
    {
        return DB::transaction(function () use ($penaltyId, $resolvedBy, $date) {

            /** @var Penalty $penalty */
            $penalty = Penalty::lockForUpdate()->findOrFail($penaltyId);

            $currentStatus = $penalty->status ?? 'unpaid';
            if ($currentStatus === 'paid') {
                return $penalty;
            }

            if ($currentStatus === 'waived') {
                throw new \Exception('Cannot pay a waived penalty.');
            }

            $paidAt = $date ? Carbon::parse($date)->endOfDay() : now();

            $penalty->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
                'resolved_by' => $resolvedBy,
            ]);

            $this->ledger->record(
                type: 'penalty_paid',
                debit: 0,
                credit: (float) $penalty->amount,
                userId: (int) $penalty->user_id,
                reference: 'Penalty paid ID ' . $penalty->id . ': ' . $penalty->reason,
                createdBy: $resolvedBy,
                sourceType: 'penalty',
                sourceId: (int) $penalty->id
            );

            return $penalty->refresh();
        });
    }

    /**
     * Waive penalty (for API)
     */
    public function waive(int $penaltyId, int $resolvedBy, ?string $date = null): Penalty
    {
        return DB::transaction(function () use ($penaltyId, $resolvedBy, $date) {

            /** @var Penalty $penalty */
            $penalty = Penalty::lockForUpdate()->findOrFail($penaltyId);

            $currentStatus = $penalty->status ?? 'unpaid';

            if ($currentStatus === 'waived') {
                return $penalty;
            }

            if ($currentStatus === 'paid') {
                throw new \Exception('Cannot waive a paid penalty.');
            }

            $resolvedAt = $date ? Carbon::parse($date)->endOfDay() : now();

            $penalty->update([
                'status'      => 'waived',
                'paid_at'     => $resolvedAt,
                'resolved_by' => $resolvedBy,
            ]);

            return $penalty->refresh();
        });
    }

    /* ==========================
       CORE PENALTY HANDLER
       ========================== */

    protected function apply(
        int $memberId,
        string $sourceType,
        ?int $sourceId,
        float $amount,
        string $reason,
        int $recordedBy,
        ?string $date = null
    ): ?Penalty {
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use (
            $memberId,
            $sourceType,
            $sourceId,
            $amount,
            $reason,
            $recordedBy,
            $date
        ) {
            try {
                $penalty = Penalty::create([
                    'user_id'     => $memberId,
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'amount'      => $amount,
                    'reason'      => $reason,
                    'status'      => 'unpaid',
                    'created_at'  => $date ? Carbon::parse($date)->startOfDay() : now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $code = $e->errorInfo[1] ?? null;
                if ((int)$code === 1062) {
                    return Penalty::where('user_id', $memberId)
                        ->where('source_type', $sourceType)
                        ->where('source_id', $sourceId)
                        ->where('reason', $reason)
                        ->first();
                }
                throw $e;
            }
            return $penalty;
        });
    }
}
