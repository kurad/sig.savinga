<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\SystemRule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContributionService
{
    public function __construct(
        protected TransactionService $ledger,
        protected PenaltyService $penaltyService,
        protected CommitmentService $commitmentService,
    ) {}

    public function record(
        int $memberId,
        float $amount,
        ?string $expectedDate,
        string $paidDate,
        int $recordedBy,
        ?string $period = null
    ): array {
        $tz = 'Africa/Kigali';
        if (!$period) {
            $period = Carbon::parse($paidDate, $tz)->format('Y-m');
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException('period must be in YYYY-MM format.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0.');
        }

        return DB::transaction(function () use ($memberId, $amount, $expectedDate, $paidDate, $recordedBy, $period) {

            $rules = SystemRule::firstOrFail();

            $min = (float) ($rules->contribution_min_amount ?? 0);
            if ($amount < $min) {
                throw new InvalidArgumentException("Amount cannot be below minimum ({$min}).");
            }

            $paid = Carbon::parse($paidDate)->startOfDay();
            $startPeriodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');

            $remaining = $amount;
            $cursorPeriodKey = $startPeriodKey;

            $startEnvelope = null;

            // ✅ allocations for frontend + audit
            $allocations = [];

            // safety
            $maxSteps = 60;
            $steps = 0;

            while ($remaining > 0) {
                $steps++;
                if ($steps > $maxSteps) {
                    throw new InvalidArgumentException('Allocation exceeded safety limit. Check commitments configuration.');
                }

                // ✅ Must have a commitment for this period
                $commitment = $this->commitmentService->activeForPeriod($memberId, $cursorPeriodKey);
                if (!$commitment) {
                    throw new InvalidArgumentException(
                        "No commitment set for this member for period {$cursorPeriodKey}. Set the next cycle commitment before allocating further."
                    );
                }

                $monthlyTarget = (float) $commitment->amount;

                // Expected date: override only for START month
                $expected = ($expectedDate && $cursorPeriodKey === $startPeriodKey)
                    ? Carbon::parse($expectedDate)->startOfDay()
                    : $this->computeExpectedDateFromRules($rules, $cursorPeriodKey);

                $isLate = $paid->gt($expected);

                // lock or create envelope
                $envelope = Contribution::where('user_id', $memberId)
                    ->where('period_key', $cursorPeriodKey)
                    ->lockForUpdate()
                    ->first();

                if (!$envelope) {
                    $envelope = Contribution::create([
                        'user_id'        => $memberId,
                        'period_key'     => $cursorPeriodKey,
                        'amount'         => 0,
                        'expected_date'  => $expected,
                        'paid_date'      => null,
                        'status'         => 'paid', // will be recalculated below
                        'penalty_amount' => 0,
                        'recorded_by'    => $recordedBy,
                    ]);
                } else {
                    // only set expected_date if missing
                    if (!$envelope->expected_date) {
                        $envelope->expected_date = $expected;
                    }
                }

                $beforeAmount = (float) $envelope->amount;
                $needed = max(0, $monthlyTarget - $beforeAmount);

                // already fully covered => go next month
                if ($needed <= 0) {
                    $cursorPeriodKey = $this->nextPeriodKey($cursorPeriodKey);
                    continue;
                }

                $alloc = min($remaining, $needed);

                // update envelope values
                $afterAmount = $beforeAmount + $alloc;
                $remainingNeededAfter = max(0, $monthlyTarget - $afterAmount);

                // ✅ status: paid only if fully covered, otherwise keep "paid" or introduce "partial"
                // If you DO NOT want a new enum value, keep it as 'paid' anyway.
                // Recommended: add 'partial' in DB enum and use it here.
                $status = $isLate ? 'late' : 'paid';
                // Optional stricter:
                // $status = $remainingNeededAfter > 0 ? 'partial' : ($isLate ? 'late' : 'paid');

                $envelope->amount = $afterAmount;
                $envelope->paid_date = $paid;
                $envelope->status = $status;
                $envelope->recorded_by = $recordedBy;
                $envelope->save();

                // ledger per allocation (so statements show period-funded)
                $this->ledger->record(
                    type: 'contribution',
                    debit: 0,
                    credit: $alloc,
                    userId: $memberId,
                    reference: 'Contribution Allocation (Period ' . $cursorPeriodKey . ') ID ' . $envelope->id,
                    createdBy: $recordedBy,
                    sourceType: 'contribution',
                    sourceId: $envelope->id
                );

                // ✅ Late penalty only once per envelope (avoid duplicates on top-ups)
                if ($isLate && (float) ($envelope->penalty_amount ?? 0) <= 0) {
                    $penalty = $this->penaltyService->contributionLate(
                        memberId: $memberId,
                        contributionId: $envelope->id,
                        recordedBy: $recordedBy,
                        periodKey: $cursorPeriodKey,
                        paidDate: $paid->toDateString()
                    );

                    if ($penalty) {
                        $envelope->penalty_amount = (float) $penalty->amount;
                        $envelope->save();
                    }
                }

                // ✅ allocation row for UI
                $allocations[] = [
                    'period_key'            => $cursorPeriodKey,
                    'monthly_target'        => $monthlyTarget,
                    'before_amount'         => $beforeAmount,
                    'allocated'             => (float) $alloc,
                    'after_amount'          => $afterAmount,
                    'remaining_needed_after' => $remainingNeededAfter,

                    'contribution_id'       => (int) $envelope->id,
                    'status'                => (string) $envelope->status,
                    'penalty_amount'        => (float) ($envelope->penalty_amount ?? 0),
                    'expected_date'         => $envelope->expected_date ? Carbon::parse($envelope->expected_date)->toDateString() : null,
                    'paid_date'             => $paid->toDateString(),
                ];

                if ($cursorPeriodKey === $startPeriodKey) {
                    $startEnvelope = $envelope;
                }

                $remaining -= $alloc;

                if ($remaining > 0) {
                    $cursorPeriodKey = $this->nextPeriodKey($cursorPeriodKey);
                }
            }

            $start = ($startEnvelope ?: Contribution::where('user_id', $memberId)
                ->where('period_key', $startPeriodKey)
                ->firstOrFail())
                ->refresh();

            return [
                'start' => $start,
                'allocations' => $allocations,
            ];
        });
    }


    public function markMissed(
        int $memberId,
        string $expectedDate,
        int $recordedBy
    ): Contribution {
        // (keep your existing markMissed as-is)
        // ...
        throw new \RuntimeException('markMissed() not included here (keep yours).');
    }

    public function markMissedByPeriod(
        int $memberId,
        string $period,
        int $recordedBy,
        ?string $expectedDate = null
    ): Contribution {
        return DB::transaction(function () use ($memberId, $period, $recordedBy, $expectedDate) {

            $rules = SystemRule::firstOrFail();

            // Normalize period key
            $periodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');

            // ✅ Must have commitment for that period (since your system enforces fixed amount per cycle)
            $commitment = $this->commitmentService->activeForPeriod($memberId, $periodKey);
            if (!$commitment) {
                throw new InvalidArgumentException(
                    "No commitment set for this member for period {$periodKey}. Cannot mark missed."
                );
            }

            // Compute expected date from rules unless an override is provided
            $expected = $expectedDate
                ? Carbon::parse($expectedDate)->startOfDay()
                : $this->computeExpectedDateFromRules($rules, $periodKey);

            // Prevent multiple rows for the same month (paid/late/missed)
            $existing = Contribution::where('user_id', $memberId)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // If you want stricter behavior, you can throw when status != missed.
                // For now, keep it idempotent:
                return $existing;
            }

            // Create missed envelope
            $contribution = Contribution::create([
                'user_id'        => $memberId,
                'amount'         => 0,
                'period_key'     => $periodKey,
                'expected_date'  => $expected,
                'paid_date'      => null,
                'status'         => 'missed',
                'penalty_amount' => 0,
                'recorded_by'    => $recordedBy,
            ]);

            // Apply missed penalty (PenaltyService will handle ledger entry)
            $penalty = $this->penaltyService->contributionMissed(
                memberId: $memberId,
                contributionId: $contribution->id,
                recordedBy: $recordedBy
            );

            if ($penalty) {
                $contribution->update(['penalty_amount' => (float) $penalty->amount]);
            }

            return $contribution->refresh();
        });
    }

    private function computeExpectedDateFromRules(SystemRule $rules, string $periodKey): Carbon
    {
        $dueDay = (int) ($rules->contribution_due_day ?? 25);

        $first = Carbon::createFromFormat('Y-m-d', $periodKey . '-01')->startOfDay();
        $lastDay = $first->copy()->endOfMonth()->day;

        return $first->copy()->day(min($dueDay, $lastDay))->startOfDay();
    }

    private function nextPeriodKey(string $periodKey): string
    {
        return Carbon::createFromFormat('Y-m', $periodKey)
            ->startOfMonth()
            ->addMonth()
            ->format('Y-m');
    }
    public function totalContributionsForMember(int $memberId): float
    {
        return (float) \App\Models\Contribution::where('user_id', $memberId)->sum('amount');
    }

    public function contributedMonthsCount(int $memberId): int
    {
        // count distinct months the member has any contribution record for
        return (int) \App\Models\Contribution::where('user_id', $memberId)
            ->select('period_key')
            ->distinct()
            ->count('period_key');
    }
    public function openingBalanceForMember(int $memberId): float
    {
        // Adjust column names if yours differ
        return (float) DB::table('opening_balances')
            ->where('user_id', $memberId)
            ->sum('amount');
    }
    public function savingsBaseForLoanLimit(int $memberId): float
    {
        $contrib = (float) $this->totalContributionsForMember($memberId);
        $opening = (float) $this->openingBalanceForMember($memberId);

        return round($contrib + $opening, 2);
    }
    public function recordSinglePeriod(
    int $memberId,
    float $amount,
    ?string $expectedDate,
    string $paidDate,
    int $recordedBy,
    ?string $period = null
): array {
    if (!$period) {
        throw new InvalidArgumentException('period (YYYY-MM) is required.');
    }

    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Amount must be greater than 0.');
    }

    return DB::transaction(function () use ($memberId, $amount, $expectedDate, $paidDate, $recordedBy, $period) {

        $rules = SystemRule::firstOrFail();

        $min = (float) ($rules->contribution_min_amount ?? 0);
        if ($amount < $min) {
            throw new InvalidArgumentException("Amount cannot be below minimum ({$min}).");
        }

        $paid = Carbon::parse($paidDate)->startOfDay();
        $periodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');

        // ✅ Must have a commitment for THIS period only
        $commitment = $this->commitmentService->activeForPeriod($memberId, $periodKey);
        if (!$commitment) {
            throw new InvalidArgumentException(
                "No commitment set for this member for period {$periodKey}. Set the commitment for this month before recording."
            );
        }

        $monthlyTarget = (float) $commitment->amount;

        // Expected date: use override if provided, else compute from rules
        $expected = $expectedDate
            ? Carbon::parse($expectedDate)->startOfDay()
            : $this->computeExpectedDateFromRules($rules, $periodKey);

        $isLate = $paid->gt($expected);

        // lock or create envelope
        $envelope = Contribution::where('user_id', $memberId)
            ->where('period_key', $periodKey)
            ->lockForUpdate()
            ->first();

        if (!$envelope) {
            $envelope = Contribution::create([
                'user_id'        => $memberId,
                'period_key'     => $periodKey,
                'amount'         => 0,
                'expected_date'  => $expected,
                'paid_date'      => null,
                'status'         => 'paid',
                'penalty_amount' => 0,
                'recorded_by'    => $recordedBy,
            ]);
        } else {
            if (!$envelope->expected_date) {
                $envelope->expected_date = $expected;
            }
        }

        $beforeAmount = (float) $envelope->amount;

        // ✅ Add full amount to THIS month only (can exceed target)
        $afterAmount = $beforeAmount + $amount;

        // If you support 'partial', you can decide status more strictly.
        $status = $isLate ? 'late' : 'paid';

        $envelope->amount = $afterAmount;
        $envelope->paid_date = $paid;
        $envelope->status = $status;
        $envelope->recorded_by = $recordedBy;
        $envelope->save();

        // ledger entry (single)
        $this->ledger->record(
            type: 'contribution',
            debit: 0,
            credit: $amount,
            userId: $memberId,
            reference: 'Contribution (Single Period ' . $periodKey . ') ID ' . $envelope->id,
            createdBy: $recordedBy,
            sourceType: 'contribution',
            sourceId: $envelope->id
        );

        // late penalty (only once)
        if ($isLate && (float) ($envelope->penalty_amount ?? 0) <= 0) {
            $penalty = $this->penaltyService->contributionLate(
                memberId: $memberId,
                contributionId: $envelope->id,
                recordedBy: $recordedBy,
                periodKey: $periodKey,
                paidDate: $paid->toDateString()
            );

            if ($penalty) {
                $envelope->penalty_amount = (float) $penalty->amount;
                $envelope->save();
            }
        }

        // for UI
        $remainingNeededAfter = max(0, $monthlyTarget - $afterAmount);

        return [
            'start' => $envelope->refresh(),
            'allocations' => [[
                'period_key'             => $periodKey,
                'monthly_target'         => $monthlyTarget,
                'before_amount'          => $beforeAmount,
                'allocated'              => (float) $amount,
                'after_amount'           => $afterAmount,
                'remaining_needed_after' => $remainingNeededAfter,
                'contribution_id'        => (int) $envelope->id,
                'status'                 => (string) $envelope->status,
                'penalty_amount'         => (float) ($envelope->penalty_amount ?? 0),
                'expected_date'          => $envelope->expected_date ? Carbon::parse($envelope->expected_date)->toDateString() : null,
                'paid_date'              => $paid->toDateString(),
            ]],
        ];
    });
}

}
