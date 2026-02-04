<?php

namespace App\Services;

use App\Models\ContributionCommitment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommitmentService
{
    public function cycleWindow(string $period, string $anchor, int $cycleMonths): array
    {
        $p = \Carbon\Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $a = \Carbon\Carbon::createFromFormat('Y-m', $anchor)->startOfMonth();

        if ($cycleMonths <= 0) {
            throw new InvalidArgumentException("cycleMonths must be > 0.");
        }

        if ($p->lt($a)) {
            throw new InvalidArgumentException("Period {$period} is before cycle anchor {$anchor}.");
        }

        $diffMonths = $a->diffInMonths($p);
        $cycleIndex = intdiv($diffMonths, $cycleMonths);

        $start = $a->copy()->addMonths($cycleIndex * $cycleMonths);
        $end   = $start->copy()->addMonths($cycleMonths - 1);

        return [$start->format('Y-m'), $end->format('Y-m')];
    }

    public function isCycleStart(string $period, string $anchor, int $cycleMonths): bool
    {
        [$cycleStart] = $this->cycleWindow($period, $anchor, $cycleMonths);
        return $period === $cycleStart;
    }

    public function setForCycle(
        int $userId,
        float $amount,
        string $cycleStart,
        string $cycleEnd,
        int $cycleMonths,
        int $createdBy
    ): ContributionCommitment {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Commitment amount must be > 0.");
        }

        return DB::transaction(function () use ($userId, $amount, $cycleStart, $cycleEnd, $cycleMonths, $createdBy) {

            // Lock existing commitment for same cycle (if any)
            $existing = ContributionCommitment::where('user_id', $userId)
                ->where('cycle_start_period', $cycleStart)
                ->where('cycle_end_period', $cycleEnd)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->update([
                    'amount' => $amount,
                    'status' => 'active',
                ]);

                return $existing->refresh();
            }

            // Expire any overlapping active commitments (safety)
            ContributionCommitment::where('user_id', $userId)
                ->where('status', 'active')
                ->where('cycle_end_period', '>=', $cycleStart)
                ->lockForUpdate()
                ->update(['status' => 'expired']);

            return ContributionCommitment::create([
                'user_id'            => $userId,
                'amount'             => $amount,
                'cycle_start_period' => $cycleStart,
                'cycle_end_period'   => $cycleEnd,
                'cycle_months'       => $cycleMonths,
                'status'             => 'active',
                'created_by'         => $createdBy,
                'activated_at'       => now(),
            ]);
        });
    }

    public function activeForPeriod(int $userId, string $periodKey): ?ContributionCommitment
    {
        return ContributionCommitment::query()
            ->where('user_id', $userId)
            ->where('cycle_start_period', '<=', $periodKey)
            ->where('cycle_end_period', '>=', $periodKey)
            ->where('status', 'active')
            ->orderByDesc('cycle_start_period')
            ->first();
    }
}
