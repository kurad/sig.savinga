<?php

namespace App\Services;

use App\Models\Penalty;
use App\Models\SystemRule;
use App\Models\GraceWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PenaltyService
{
    protected string $tz = 'Africa/Kigali';

    public function __construct(
        protected TransactionService $ledger
    ) {}

    protected function nowTz(): Carbon
    {
        return now($this->tz);
    }

    protected function normalizeDate(?string $date = null, bool $endOfDay = false): Carbon
    {
        $d = $date
            ? Carbon::parse($date, $this->tz)
            : $this->nowTz();

        return $endOfDay ? $d->copy()->endOfDay() : $d->copy()->startOfDay();
    }

    protected function validateOwner(?int $userId, ?int $beneficiaryId): void
    {
        if (is_null($userId) && is_null($beneficiaryId)) {
            throw new InvalidArgumentException(
                'A penalty must belong to at least a user or a beneficiary.'
            );
        }

        if (!is_null($userId) && $userId <= 0) {
            throw new InvalidArgumentException('user_id must be a valid positive integer.');
        }

        if (!is_null($beneficiaryId) && $beneficiaryId <= 0) {
            throw new InvalidArgumentException('beneficiary_id must be a valid positive integer.');
        }
    }

    protected function ownerPayload(?int $userId, ?int $beneficiaryId): array
    {
        $this->validateOwner($userId, $beneficiaryId);

        return [
            'user_id' => $userId,
            'beneficiary_id' => $beneficiaryId,
        ];
    }

    protected function ownerPenaltyQuery(?int $userId, ?int $beneficiaryId)
    {
        $this->validateOwner($userId, $beneficiaryId);

        return Penalty::query()
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId));
    }

    protected function isLateContributionPenaltyExempt(string $periodKey, Carbon $paidDate): bool
    {
        return GraceWindow::query()
            ->where('period_key', $periodKey)
            ->whereDate('start_date', '<=', $paidDate->toDateString())
            ->whereDate('end_date', '>=', $paidDate->toDateString())
            ->exists();
    }

    protected function lateContributionPenaltyRate(): float
    {
        $rules = SystemRule::firstOrFail();

        return round((float) ($rules->late_contribution_penalty_percent ?? 0), 4);
    }

    protected function missedContributionPenaltyRate(): float
    {
        $rules = SystemRule::firstOrFail();

        return round((float) ($rules->missed_contribution_penalty_percent ?? 0), 4);
    }

    protected function loanPenaltyRate(): float
    {
        $rules = SystemRule::firstOrFail();

        return round((float) ($rules->late_loan_penalty_percent ?? 0), 4);
    }

    /**
     * Late contribution penalty
     */
    public function contributionLate(
        ?int $userId,
        ?int $beneficiaryId,
        int $contributionId,
        int $recordedBy,
        string $periodKey,
        string $paidDate,
        float $principalBase = 0
    ): ?Penalty {
        $this->validateOwner($userId, $beneficiaryId);

        $paid = Carbon::parse($paidDate, $this->tz);
        if ($this->isLateContributionPenaltyExempt($periodKey, $paid)) {
            return null;
        }

        $rate = $this->lateContributionPenaltyRate();
        if ($rate <= 0) {
            return null;
        }

        $baseAmount = $this->compoundBase($userId, $beneficiaryId, $principalBase, ['contribution']);
        $amount = $this->computeCompoundAmount($baseAmount, $rate);

        return $this->apply(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            sourceType: 'contribution',
            sourceId: $contributionId,
            amount: $amount,
            reason: $this->cycleReason('Late contribution penalty', $periodKey),
            recordedBy: $recordedBy,
            date: $paidDate
        );
    }

    /**
     * Missed contribution penalty
     */
    public function contributionMissed(
        ?int $userId,
        ?int $beneficiaryId,
        int $contributionId,
        int $recordedBy,
        string $periodKey = '',
        float $principalBase = 0,
        ?string $date = null
    ): ?Penalty {
        $this->validateOwner($userId, $beneficiaryId);

        $rate = $this->missedContributionPenaltyRate();
        if ($rate <= 0) {
            return null;
        }

        $baseAmount = $this->compoundBase($userId, $beneficiaryId, $principalBase, ['contribution']);
        $amount = $this->computeCompoundAmount($baseAmount, $rate);

        return $this->apply(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            sourceType: 'contribution',
            sourceId: $contributionId,
            amount: $amount,
            reason: $periodKey
                ? $this->cycleReason('Missed contribution penalty', $periodKey)
                : 'Missed contribution penalty',
            recordedBy: $recordedBy,
            date: $date
        );
    }

    /**
     * Late loan repayment penalty
     */
    public function loanLate(
        ?int $userId,
        ?int $beneficiaryId,
        int $loanId,
        int $recordedBy,
        string $periodKey,
        float $principalBase,
        ?string $date = null
    ): ?Penalty {
        $this->validateOwner($userId, $beneficiaryId);

        $rate = $this->loanPenaltyRate();
        if ($rate <= 0) {
            return null;
        }

        $baseAmount = $this->compoundBase($userId, $beneficiaryId, $principalBase, ['loan', 'loan_installment']);
        $amount = $this->computeCompoundAmount($baseAmount, $rate);

        return $this->apply(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            sourceType: 'loan',
            sourceId: $loanId,
            amount: $amount,
            reason: $this->cycleReason('Late loan repayment penalty', $periodKey),
            recordedBy: $recordedBy,
            date: $date
        );
    }

    public function loanInstallmentLate(
        ?int $userId,
        ?int $beneficiaryId,
        int $loanId,
        int $installmentId,
        int $recordedBy,
        string $periodKey,
        float $principalBase,
        ?string $date = null
    ): ?Penalty {
        $this->validateOwner($userId, $beneficiaryId);

        $rate = $this->loanPenaltyRate();
        if ($rate <= 0) {
            return null;
        }

        $baseAmount = $this->compoundBase($userId, $beneficiaryId, $principalBase, ['loan', 'loan_installment']);
        $amount = $this->computeCompoundAmount($baseAmount, $rate);

        return $this->apply(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            sourceType: 'loan_installment',
            sourceId: $installmentId,
            amount: $amount,
            reason: $this->cycleReason('Late loan installment penalty', $periodKey),
            recordedBy: $recordedBy,
            date: $date
        );
    }

    /**
     * Manual penalty
     */
    public function manual(
        ?int $userId,
        ?int $beneficiaryId,
        float $amount,
        string $reason,
        int $recordedBy,
        ?string $date = null
    ): ?Penalty {
        return $this->apply(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            sourceType: 'manual',
            sourceId: null,
            amount: $amount,
            reason: $reason,
            recordedBy: $recordedBy,
            date: $date
        );
    }

    /**
     * Mark penalty as paid
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

            $paidAt = $date
                ? Carbon::parse($date, $this->tz)->endOfDay()
                : $this->nowTz()->copy()->endOfDay();

            $penalty->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
                'resolved_by' => $resolvedBy,
                'updated_at' => $this->nowTz(),
            ]);

            $this->ledger->record(
                type: 'penalty_paid',
                debit: 0,
                credit: (float) $penalty->amount,
                userId: $penalty->user_id,
                beneficiaryId: $penalty->beneficiary_id,
                reference: 'Penalty paid ID ' . $penalty->id . ': ' . $penalty->reason,
                createdBy: $resolvedBy,
                sourceType: 'penalty',
                sourceId: (int) $penalty->id
            );

            return $penalty->refresh();
        });
    }

    /**
     * Waive penalty
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

            $resolvedAt = $date
                ? Carbon::parse($date, $this->tz)->endOfDay()
                : $this->nowTz()->copy()->endOfDay();

            $penalty->update([
                'status' => 'waived',
                'paid_at' => $resolvedAt,
                'resolved_by' => $resolvedBy,
                'updated_at' => $this->nowTz(),
            ]);

            return $penalty->refresh();
        });
    }

    protected function apply(
        ?int $userId,
        ?int $beneficiaryId,
        string $sourceType,
        ?int $sourceId,
        float $amount,
        string $reason,
        int $recordedBy,
        ?string $date = null,
        string $status = 'unpaid'
    ): ?Penalty {
        $this->validateOwner($userId, $beneficiaryId);

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use (
            $userId,
            $beneficiaryId,
            $sourceType,
            $sourceId,
            $amount,
            $reason,
            $recordedBy,
            $date,
            $status
        ) {
            try {
                $penalty = Penalty::create([
                    ...$this->ownerPayload($userId, $beneficiaryId),
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'amount' => $amount,
                    'reason' => $reason,
                    'status' => $status,
                    'created_at' => $this->normalizeDate($date),
                    'updated_at' => $this->nowTz(),
                    'paid_at' => $status === 'paid' ? $this->normalizeDate($date, true) : null,
                    'resolved_by' => $status === 'paid' ? $recordedBy : null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $code = $e->errorInfo[1] ?? null;

                if ((int) $code === 1062) {
                    return $this->ownerPenaltyQuery($userId, $beneficiaryId)
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

    /**
     * Total unpaid penalties for an owner.
     */
    public function outstandingAmount(?int $userId, ?int $beneficiaryId): float
    {
        return round((float) $this->ownerPenaltyQuery($userId, $beneficiaryId)
            ->where('status', 'unpaid')
            ->sum('amount'), 2);
    }

    protected function compoundBase(
        ?int $userId,
        ?int $beneficiaryId,
        float $principalBase,
        array $sourceTypes = []
    ): float {
        $principalBase = round((float) $principalBase, 2);

        $query = $this->ownerPenaltyQuery($userId, $beneficiaryId)
            ->where('status', 'unpaid');

        if (!empty($sourceTypes)) {
            $query->whereIn('source_type', $sourceTypes);
        }

        $unpaidPenalties = round((float) $query->sum('amount'), 2);

        return round($principalBase + $unpaidPenalties, 2);
    }

    protected function computeCompoundAmount(float $baseAmount, float $ratePercent): float
    {
        return round($baseAmount * ($ratePercent / 100), 2);
    }

    protected function cycleReason(string $baseReason, string $periodKey): string
    {
        return "{$baseReason} - {$periodKey}";
    }

    public function settleFromPayroll(
        ?int $userId,
        ?int $beneficiaryId,
        float $amount,
        string $paidDate,
        int $recordedBy
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return [
                'paid_total' => 0.0,
                'remaining_unallocated' => 0.0,
                'items' => [],
            ];
        }

        $paidAt = Carbon::parse($paidDate, $this->tz)->endOfDay();

        return DB::transaction(function () use ($userId, $beneficiaryId, $amount, $paidAt, $recordedBy) {
            $remaining = round($amount, 2);

            $penalties = $this->ownerPenaltyQuery($userId, $beneficiaryId)
                ->where('status', 'unpaid')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            $items = [];
            $paidTotal = 0.0;

            foreach ($penalties as $p) {
                if ($remaining <= 0) {
                    break;
                }

                $need = round((float) $p->amount, 2);
                if ($need <= 0) {
                    continue;
                }

                if ($remaining + 0.00001 < $need) {
                    break;
                }

                $this->markPaid(
                    penaltyId: (int) $p->id,
                    resolvedBy: $recordedBy,
                    date: $paidAt->toDateString()
                );

                $items[] = [
                    'penalty_id' => (int) $p->id,
                    'amount' => $need,
                    'reason' => (string) $p->reason,
                ];

                $remaining = round($remaining - $need, 2);
                $paidTotal = round($paidTotal + $need, 2);
            }

            return [
                'paid_total' => $paidTotal,
                'remaining_unallocated' => $remaining,
                'items' => $items,
            ];
        });
    }

    public function createAndMarkPaid(
        ?int $userId,
        ?int $beneficiaryId,
        string $sourceType,
        ?int $sourceId,
        float $amount,
        string $reason,
        int $recordedBy,
        string $paidDate
    ): ?Penalty {
        $this->validateOwner($userId, $beneficiaryId);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use (
            $userId,
            $beneficiaryId,
            $sourceType,
            $sourceId,
            $amount,
            $reason,
            $recordedBy,
            $paidDate
        ) {
            $penalty = Penalty::create([
                ...$this->ownerPayload($userId, $beneficiaryId),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'amount' => round((float) $amount, 2),
                'reason' => $reason,
                'status' => 'paid',
                'created_at' => Carbon::parse($paidDate, $this->tz)->startOfDay(),
                'updated_at' => $this->nowTz(),
                'paid_at' => Carbon::parse($paidDate, $this->tz)->endOfDay(),
                'resolved_by' => $recordedBy,
            ]);

            $this->ledger->record(
                type: 'penalty_paid',
                debit: 0,
                credit: (float) $penalty->amount,
                userId: $penalty->user_id,
                beneficiaryId: $penalty->beneficiary_id,
                reference: 'Penalty paid ID ' . $penalty->id . ': ' . $penalty->reason,
                createdBy: $recordedBy,
                sourceType: 'penalty',
                sourceId: (int) $penalty->id
            );

            return $penalty;
        });
    }
}