<?php

namespace App\Services;

use App\Models\ContributionCommitment;
use Carbon\Carbon;
use InvalidArgumentException;
use App\Models\Contribution;
use App\Models\SystemRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            ->where('status', 'active')
            ->where('cycle_start_period', '<=', $periodKey)
            ->where(function ($q) use ($periodKey) {
                $q->whereNull('cycle_end_period')
                    ->orWhere('cycle_end_period', '>=', $periodKey);
            });

        if ($beneficiaryId !== null) {
            $query->where('user_id', $userId)
                ->where('beneficiary_id', $beneficiaryId);
        } else {
            $query->where('user_id', $userId)
                ->whereNull('beneficiary_id');
        }

        return $query
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->first();
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
    public function updateAmountOrStartNewCycle(
        ContributionCommitment $commitment,
        int $userId,
        ?int $beneficiaryId,
        float $amount,
        ?string $requestedStartPeriod,
        int $createdBy
    ): array {
        return DB::transaction(function () use (
            $commitment,
            $userId,
            $beneficiaryId,
            $amount,
            $requestedStartPeriod,
            $createdBy
        ) {
            $this->validateOwner($userId, $beneficiaryId);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Amount must be greater than 0.'],
                ]);
            }

            $sameOwner =
                (int) $commitment->user_id === (int) $userId &&
                ((int) ($commitment->beneficiary_id ?? 0) === (int) ($beneficiaryId ?? 0));

            if (!$sameOwner) {
                throw ValidationException::withMessages([
                    'participant' => ['This commitment does not belong to the selected participant.'],
                ]);
            }

            $hasRecordedContributions = Contribution::query()
                ->where('user_id', $userId)
                ->when(
                    $beneficiaryId !== null,
                    fn($q) => $q->where('beneficiary_id', $beneficiaryId),
                    fn($q) => $q->whereNull('beneficiary_id')
                )
                ->where('period_key', '>=', $commitment->cycle_start_period)
                ->where('period_key', '<=', $commitment->cycle_end_period)
                ->exists();

            // Case 1: no contributions yet -> just update amount
            if (!$hasRecordedContributions) {
                $commitment->update([
                    'amount' => $amount,
                ]);

                return [
                    'message' => 'Commitment amount updated successfully.',
                    'data' => $commitment->fresh(),
                ];
            }

            // Case 2: contributions already recorded -> create a new cycle
            if (!$requestedStartPeriod) {
                throw ValidationException::withMessages([
                    'cycle_start_period' => [
                        'A new cycle start period is required because contributions have already been recorded for the current cycle.',
                    ],
                ]);
            }

            $this->assertPeriodKey($requestedStartPeriod);

            $rules = SystemRule::firstOrFail();
            $cycleMonths = (int) ($rules->contribution_cycle_months ?? 12);
            $anchor = (string) ($rules->cycle_anchor_period ?? $requestedStartPeriod);

            [$cycleStart, $cycleEnd] = $this->cycleWindow(
                period: $requestedStartPeriod,
                anchor: $anchor,
                cycleMonths: $cycleMonths
            );

            if ($requestedStartPeriod !== $cycleStart) {
                throw ValidationException::withMessages([
                    'cycle_start_period' => [
                        "New commitment can only start at cycle start ({$cycleStart}).",
                    ],
                ]);
            }

            if ($cycleStart <= $commitment->cycle_start_period) {
                throw ValidationException::withMessages([
                    'cycle_start_period' => [
                        'New cycle must start after the current commitment cycle start period.',
                    ],
                ]);
            }

            $overlap = ContributionCommitment::query()
                ->where('user_id', $userId)
                ->when(
                    $beneficiaryId !== null,
                    fn($q) => $q->where('beneficiary_id', $beneficiaryId),
                    fn($q) => $q->whereNull('beneficiary_id')
                )
                ->where('status', 'active')
                ->where('id', '!=', $commitment->id)
                ->where(function ($q) use ($cycleStart, $cycleEnd) {
                    $q->whereBetween('cycle_start_period', [$cycleStart, $cycleEnd])
                        ->orWhereBetween('cycle_end_period', [$cycleStart, $cycleEnd])
                        ->orWhere(function ($sub) use ($cycleStart, $cycleEnd) {
                            $sub->where('cycle_start_period', '<=', $cycleStart)
                                ->where('cycle_end_period', '>=', $cycleEnd);
                        });
                })
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'cycle_start_period' => ['There is already an active commitment overlapping this new cycle.'],
                ]);
            }

            // expire current one
            $commitment->update([
                'status' => 'expired',
            ]);

            // create the new one
            $newCommitment = ContributionCommitment::create([
                'user_id' => $userId,
                'beneficiary_id' => $beneficiaryId,
                'amount' => $amount,
                'cycle_start_period' => $cycleStart,
                'cycle_end_period' => $cycleEnd,
                'cycle_months' => $cycleMonths,
                'status' => 'active',
                'activated_at' => now(),
                'created_by' => $createdBy,
            ]);

            return [
                'message' => 'Current commitment already has recorded contributions. A new cycle commitment has been created instead.',
                'data' => $newCommitment,
            ];
        });
    }
}
