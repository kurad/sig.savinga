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

        if ($cycleMonths <= 0) {
            throw new InvalidArgumentException('cycleMonths must be greater than 0.');
        }

        $p = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $a = Carbon::createFromFormat('Y-m', $anchor)->startOfMonth();

        if ($p->lt($a)) {
            throw new InvalidArgumentException("Period {$period} is before cycle anchor {$anchor}.");
        }

        $diffMonths = $a->diffInMonths($p);
        $cycleIndex = intdiv($diffMonths, $cycleMonths);

        $start = $a->copy()->addMonthsNoOverflow($cycleIndex * $cycleMonths);
        $end = $start->copy()->addMonthsNoOverflow($cycleMonths - 1);

        return [$start->format('Y-m'), $end->format('Y-m')];
    }

    public function isCycleStart(string $period, string $anchor, int $cycleMonths): bool
    {
        [$cycleStart] = $this->cycleWindow($period, $anchor, $cycleMonths);
        return $period === $cycleStart;
    }

    public function activeForPeriod(
        int $userId,
        ?int $beneficiaryId,
        string $periodKey
    ): ?ContributionCommitment {
        $this->validateOwner($userId, $beneficiaryId);
        $this->assertPeriodKey($periodKey);

        $query = ContributionCommitment::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where('cycle_start_period', '<=', $periodKey)
            ->where('cycle_end_period', '>=', $periodKey)
            ->orderByDesc('activated_at')
            ->orderByDesc('id');

        if (is_null($beneficiaryId)) {
            $query->whereNull('beneficiary_id');
        } else {
            $query->where('beneficiary_id', $beneficiaryId);
        }

        return $query->first();
    }
    public function setForCycle(
        int $userId,
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
            throw new InvalidArgumentException('Commitment amount must be greater than 0.');
        }

        if ($cycleMonths <= 0) {
            throw new InvalidArgumentException('cycleMonths must be greater than 0.');
        }

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
            $baseQuery = ContributionCommitment::query()
                ->where('user_id', $userId);

            if (is_null($beneficiaryId)) {
                $baseQuery->whereNull('beneficiary_id');
            } else {
                $baseQuery->where('beneficiary_id', $beneficiaryId);
            }

            $existingExact = (clone $baseQuery)
                ->where('cycle_start_period', $cycleStart)
                ->where('cycle_end_period', $cycleEnd)
                ->lockForUpdate()
                ->first();

            if ($existingExact) {
                $existingExact->update([
                    'amount' => $amount,
                    'cycle_months' => $cycleMonths,
                    'status' => 'active',
                    'activated_at' => now('Africa/Kigali'),
                ]);

                return $existingExact->refresh();
            }

            (clone $baseQuery)
                ->where('status', 'active')
                ->where(function ($q) use ($cycleStart, $cycleEnd) {
                    $q->where('cycle_end_period', '>=', $cycleStart)
                        ->where('cycle_start_period', '<=', $cycleEnd);
                })
                ->lockForUpdate()
                ->update([
                    'status' => 'expired',
                ]);

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
        int $userId,
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
        int $userId,
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
    public function expire(ContributionCommitment $commitment): ContributionCommitment
    {
        if ($commitment->status !== 'expired') {
            $commitment->update([
                'status' => 'expired',
            ]);
        }

        return $commitment->refresh();
    }

    /**
     * Conservative update aligned with schema.
     */
    public function updateCommitment(
        ContributionCommitment $commitment,
        int $userId,
        ?int $beneficiaryId,
        float $amount,
        string $cycleStart,
        string $cycleEnd,
        int $cycleMonths,
        string $status,
        $activatedAt = null
    ): ContributionCommitment {
        $this->validateOwner($userId, $beneficiaryId);
        $this->assertPeriodKey($cycleStart);
        $this->assertPeriodKey($cycleEnd);

        $amount = round((float) $amount, 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Commitment amount must be greater than 0.');
        }

        if ($cycleMonths <= 0) {
            throw new InvalidArgumentException('cycleMonths must be greater than 0.');
        }

        if ($cycleEnd < $cycleStart) {
            throw new InvalidArgumentException('cycleEnd cannot be before cycleStart.');
        }

        if (!in_array($status, ['active', 'expired'], true)) {
            throw new InvalidArgumentException('Invalid commitment status.');
        }

        return DB::transaction(function () use (
            $commitment,
            $userId,
            $beneficiaryId,
            $amount,
            $cycleStart,
            $cycleEnd,
            $cycleMonths,
            $status,
            $activatedAt
        ) {
            $conflictQuery = ContributionCommitment::query()
                ->where('id', '!=', $commitment->id)
                ->where('user_id', $userId)
                ->where('cycle_start_period', $cycleStart)
                ->where('cycle_end_period', $cycleEnd);

            if (is_null($beneficiaryId)) {
                $conflictQuery->whereNull('beneficiary_id');
            } else {
                $conflictQuery->where('beneficiary_id', $beneficiaryId);
            }

            $conflict = $conflictQuery->lockForUpdate()->first();

            if ($conflict) {
                throw new InvalidArgumentException('A commitment already exists for this user/beneficiary and cycle.');
            }

            if ($status === 'active') {
                $overlapQuery = ContributionCommitment::query()
                    ->where('id', '!=', $commitment->id)
                    ->where('user_id', $userId)
                    ->where('status', 'active')
                    ->where(function ($q) use ($cycleStart, $cycleEnd) {
                        $q->where('cycle_end_period', '>=', $cycleStart)
                            ->where('cycle_start_period', '<=', $cycleEnd);
                    });

                if (is_null($beneficiaryId)) {
                    $overlapQuery->whereNull('beneficiary_id');
                } else {
                    $overlapQuery->where('beneficiary_id', $beneficiaryId);
                }

                $overlapQuery->lockForUpdate()->update([
                    'status' => 'expired',
                ]);
            }

            $commitment->update([
                'user_id' => $userId,
                'beneficiary_id' => $beneficiaryId,
                'amount' => $amount,
                'cycle_start_period' => $cycleStart,
                'cycle_end_period' => $cycleEnd,
                'cycle_months' => $cycleMonths,
                'status' => $status,
                'activated_at' => $activatedAt ?? $commitment->activated_at,
            ]);

            return $commitment->refresh();
        });
    }
    private function validateOwner(int $userId, ?int $beneficiaryId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('userId is required and must be a valid positive integer.');
        }

        if (!is_null($beneficiaryId) && $beneficiaryId <= 0) {
            throw new InvalidArgumentException('beneficiaryId must be a valid positive integer when provided.');
        }
    }

    private function assertPeriodKey(string $periodKey): void
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodKey)) {
            throw new InvalidArgumentException("Period key must be YYYY-MM, got: {$periodKey}");
        }
    }
}