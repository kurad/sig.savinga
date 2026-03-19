<?php

namespace App\Services;

use App\Models\ContributionCommitment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommitmentService
{
    public function cycleWindow(string $period, string $anchor, int $cycleMonths): array
    {
        $this->assertPeriodKey($period);
        $this->assertPeriodKey($anchor);

        $p = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $a = Carbon::createFromFormat('Y-m', $anchor)->startOfMonth();

        if ($cycleMonths <= 0) {
            throw new InvalidArgumentException('cycleMonths must be > 0.');
        }

        if ($p->lt($a)) {
            throw new InvalidArgumentException("Period {$period} is before cycle anchor {$anchor}.");
        }

        $diffMonths = $a->diffInMonths($p);
        $cycleIndex = intdiv($diffMonths, $cycleMonths);

        $start = $a->copy()->addMonthsNoOverflow($cycleIndex * $cycleMonths);
        $end   = $start->copy()->addMonthsNoOverflow($cycleMonths - 1);

        return [$start->format('Y-m'), $end->format('Y-m')];
    }

    public function isCycleStart(string $period, string $anchor, int $cycleMonths): bool
    {
        [$cycleStart] = $this->cycleWindow($period, $anchor, $cycleMonths);
        return $period === $cycleStart;
    }

    /**
     * Find active commitment that covers a given period (YYYY-MM).
     */
    public function activeForPeriod(
        ?int $userId,
        ?int $beneficiaryId,
        string $periodKey
    ): ?ContributionCommitment {
        $this->validateOwner($userId, $beneficiaryId);
        $this->assertPeriodKey($periodKey);

        return ContributionCommitment::query()
            ->active()
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId))
            ->coversPeriod($periodKey)
            ->orderByDesc('activated_at')
            ->first();
    }

    public function setForCycle(
        ?int $userId,
        ?int $beneficiaryId,
        float $amount,
        string $cycleStart,
        string $cycleEnd,
        int $cycleMonths,
        int $createdBy
    ): ContributionCommitment {
        $this->validateOwner($userId, $beneficiaryId);
        $this->assertPeriodKey($cycleStart);
        $this->assertPeriodKey($cycleEnd);

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Commitment amount must be > 0.');
        }

        $cycleMonths = max(1, (int) $cycleMonths);

        if ($cycleEnd < $cycleStart) {
            throw new InvalidArgumentException('cycleEnd cannot be before cycleStart.');
        }

        return DB::transaction(function () use (
            $userId,
            $beneficiaryId,
            $amount,
            $cycleStart,
            $cycleEnd,
            $cycleMonths,
            $createdBy
        ) {
            $existing = ContributionCommitment::query()
                ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
                ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId))
                ->where('cycle_start_period', $cycleStart)
                ->where('cycle_end_period', $cycleEnd)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'amount' => $amount,
                    'cycle_months' => $cycleMonths,
                    'status' => 'active',
                    'activated_at' => now('Africa/Kigali'),
                ]);

                return $existing->refresh();
            }

            ContributionCommitment::query()
                ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
                ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId))
                ->where('status', 'active')
                ->where(function ($q) use ($cycleStart, $cycleEnd) {
                    $q->where('cycle_end_period', '>=', $cycleStart)
                        ->where('cycle_start_period', '<=', $cycleEnd);
                })
                ->lockForUpdate()
                ->update(['status' => 'inactive']);

            return ContributionCommitment::create([
                'user_id' => $userId,
                'beneficiary_id' => $beneficiaryId,
                'amount' => $amount,
                'cycle_start_period' => $cycleStart,
                'cycle_end_period' => $cycleEnd,
                'cycle_months' => $cycleMonths,
                'status' => 'active',
                'created_by' => $createdBy,
                'activated_at' => now('Africa/Kigali'),
            ]);
        });
    }

    public function setForPeriod(
        ?int $userId,
        ?int $beneficiaryId,
        string $periodKey,
        float $amount,
        int $createdBy,
        string $anchorPeriod,
        int $cycleMonths
    ): ContributionCommitment {
        $this->validateOwner($userId, $beneficiaryId);
        $this->assertPeriodKey($periodKey);
        $this->assertPeriodKey($anchorPeriod);

        [$cycleStart, $cycleEnd] = $this->cycleWindow($periodKey, $anchorPeriod, $cycleMonths);

        return $this->setForCycle(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            amount: $amount,
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd,
            cycleMonths: $cycleMonths,
            createdBy: $createdBy
        );
    }

    public function ensureCoversPeriod(
        ?int $userId,
        ?int $beneficiaryId,
        string $periodKey,
        float $defaultAmount,
        int $createdBy,
        string $anchorPeriod,
        int $cycleMonths
    ): ContributionCommitment {
        $this->validateOwner($userId, $beneficiaryId);
        $this->assertPeriodKey($periodKey);

        $existing = $this->activeForPeriod($userId, $beneficiaryId, $periodKey);
        if ($existing) {
            return $existing;
        }

        return $this->setForPeriod(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            periodKey: $periodKey,
            amount: $defaultAmount,
            createdBy: $createdBy,
            anchorPeriod: $anchorPeriod,
            cycleMonths: $cycleMonths
        );
    }

    private function validateOwner(?int $userId, ?int $beneficiaryId): void
    {
        $hasUser = !is_null($userId);
        $hasBeneficiary = !is_null($beneficiaryId);

        if (($hasUser && $hasBeneficiary) || (!$hasUser && !$hasBeneficiary)) {
            throw new InvalidArgumentException(
                'A commitment must belong to either a user or a beneficiary.'
            );
        }
    }

    private function assertPeriodKey(string $periodKey): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            throw new InvalidArgumentException("Period key must be YYYY-MM, got: {$periodKey}");
        }
    }
}