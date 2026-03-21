<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\ContributionBatch;
use App\Models\ContributionAllocation;
use App\Models\FinancialYearRule;
use App\Models\MemberFinancialYear;
use App\Models\Penalty;
use App\Models\SystemRule;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ContributionService
{
    private string $tz = 'Africa/Kigali';

    public function __construct(
        protected TransactionService $ledger,
        protected PenaltyService $penaltyService,
        protected CommitmentService $commitmentService,
        protected MemberFinancialYearService $memberFinancialYearService,
    ) {}

    public function previewAllocation(
        int $userId,
        ?int $beneficiaryId,
        float $amount,
        ?string $expectedDate,
        string $paidDate,
        ?string $period = null,
        bool $strictCommitment = true,
        bool $bypassMin = false,
        ?int $financialYearRuleId = null,
        int $maxSteps = 60
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        $fy = $this->resolveFy($financialYearRuleId);
        $this->ensureOwnerFyOpen($userId, $beneficiaryId, (int) $fy->id);

        if (!$period) {
            $period = Carbon::parse($paidDate, $this->tz)->format('Y-m');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException('period must be in YYYY-MM format.');
        }

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0.');
        }

        $rules = SystemRule::firstOrFail();
        $min = (float) ($rules->contribution_min_amount ?? 0);

        if (!$bypassMin && $amount < $min) {
            throw new InvalidArgumentException("Amount cannot be below minimum ({$min}).");
        }

        $paid = Carbon::parse($paidDate, $this->tz)->startOfDay();
        $startPeriodKey = Carbon::createFromFormat('Y-m', $period, $this->tz)->format('Y-m');

        $this->assertPeriodInFY($startPeriodKey, $fy);

        $remaining = $amount;
        $cursorPeriodKey = $startPeriodKey;
        $allocations = [];
        $steps = 0;

        while ($remaining > 0) {
            $steps++;
            if ($steps > $maxSteps) {
                throw new InvalidArgumentException('Allocation exceeded safety limit. Check commitments configuration.');
            }

            $this->assertPeriodInFY($cursorPeriodKey, $fy);

            $expected = ($expectedDate && $cursorPeriodKey === $startPeriodKey)
                ? Carbon::parse($expectedDate, $this->tz)->startOfDay()
                : $this->computeExpectedDateFromFyRule($fy, $cursorPeriodKey);

            $isLate = $paid->gt($expected);

            $envelope = $this->ownerContributionQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', (int) $fy->id)
                ->where('period_key', $cursorPeriodKey)
                ->first();

            $beforeAmount = (float) ($envelope?->amount ?? 0);

            $commitment = $this->commitmentService->activeForPeriod(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                periodKey: $cursorPeriodKey
            );

            if (!$commitment && $strictCommitment) {
                throw new InvalidArgumentException(
                    "No commitment set for this owner for period {$cursorPeriodKey}. Set the next cycle commitment before allocating further."
                );
            }

            $monthlyTarget = $commitment
                ? (float) $commitment->amount
                : ($beforeAmount + $remaining);

            $needed = max(0, $monthlyTarget - $beforeAmount);

            if ($needed <= 0) {
                $cursorPeriodKey = $this->nextPeriodKey($cursorPeriodKey);
                continue;
            }

            $alloc = min($remaining, $needed);
            $afterAmount = $beforeAmount + $alloc;
            $remainingNeededAfter = max(0, $monthlyTarget - $afterAmount);
            $status = $isLate ? 'late' : 'paid';

            $allocations[] = [
                'financial_year_rule_id' => (int) $fy->id,
                'year_key'               => (string) $fy->year_key,
                'period_key'             => $cursorPeriodKey,
                'monthly_target'         => $monthlyTarget,
                'before_amount'          => round((float) $beforeAmount, 2),
                'allocated'              => round((float) $alloc, 2),
                'after_amount'           => round((float) $afterAmount, 2),
                'remaining_needed_after' => round((float) $remainingNeededAfter, 2),
                'contribution_id'        => (int) ($envelope?->id ?? 0),
                'status'                 => $status,
                'expected_date'          => $expected->toDateString(),
                'paid_date'              => $paid->toDateString(),
                'note'                   => $envelope ? 'existing_envelope' : 'would_create_envelope',
            ];

            $remaining = round($remaining - $alloc, 2);

            if ($remaining > 0) {
                $cursorPeriodKey = $this->nextPeriodKey($cursorPeriodKey);
            }
        }

        return [
            'preview' => true,
            'financial_year_rule_id' => (int) $fy->id,
            'year_key' => (string) $fy->year_key,
            'user_id' => $userId,
            'beneficiary_id' => $beneficiaryId,
            'start_period_key' => $startPeriodKey,
            'paid_date' => $paid->toDateString(),
            'expected_date_override' => $expectedDate ?: null,
            'amount' => $amount,
            'allocations' => $allocations,
            'months_affected' => count($allocations),
            'total_allocated' => round(array_sum(array_map(fn($a) => (float) $a['allocated'], $allocations)), 2),
        ];
    }

    public function record(
        int $userId,
        ?int $beneficiaryId,
        float $amount,
        ?string $expectedDate,
        string $paidDate,
        int $recordedBy,
        ?string $period = null,
        bool $strictCommitment = true,
        bool $bypassMin = false,
        ?int $financialYearRuleId = null
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        $fy = $this->resolveFy($financialYearRuleId);
        $this->ensureOwnerFyOpen($userId, $beneficiaryId, (int) $fy->id);

        if (!$period) {
            $period = Carbon::parse($paidDate, $this->tz)->format('Y-m');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException('period must be in YYYY-MM format.');
        }

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0.');
        }

        $batchRef = (string) Str::uuid();

        return DB::transaction(function () use (
            $userId,
            $beneficiaryId,
            $amount,
            $expectedDate,
            $paidDate,
            $recordedBy,
            $period,
            $strictCommitment,
            $bypassMin,
            $fy,
            $batchRef
        ) {
            $rules = SystemRule::firstOrFail();
            $min = (float) ($rules->contribution_min_amount ?? 0);

            if (!$bypassMin && $amount < $min) {
                throw new InvalidArgumentException("Amount cannot be below minimum ({$min}).");
            }

            $paid = Carbon::parse($paidDate, $this->tz)->startOfDay();
            $startPeriodKey = Carbon::createFromFormat('Y-m', $period, $this->tz)->format('Y-m');

            $this->assertPeriodInFY($startPeriodKey, $fy);

            $remaining = $amount;
            $cursorPeriodKey = $startPeriodKey;
            $startEnvelope = null;
            $allocations = [];
            $maxSteps = 60;
            $steps = 0;

            $batch = ContributionBatch::create([
                ...$this->ownerPayload($userId, $beneficiaryId),
                'financial_year_rule_id' => (int) $fy->id,
                'total_amount' => $amount,
                'paid_date' => $paid->toDateString(),
                'start_period_key' => $startPeriodKey,
                'batch_ref' => $batchRef,
                'recorded_by' => $recordedBy,
            ]);

            while ($remaining > 0) {
                $steps++;
                if ($steps > $maxSteps) {
                    throw new InvalidArgumentException(
                        'Allocation exceeded safety limit. Check commitments configuration.'
                    );
                }

                $this->assertPeriodInFY($cursorPeriodKey, $fy);

                $expected = ($expectedDate && $cursorPeriodKey === $startPeriodKey)
                    ? Carbon::parse($expectedDate, $this->tz)->startOfDay()
                    : $this->computeExpectedDateFromFyRule($fy, $cursorPeriodKey);

                $isLate = $paid->gt($expected);

                $envelope = $this->ownerContributionQuery($userId, $beneficiaryId)
                    ->where('financial_year_rule_id', (int) $fy->id)
                    ->where('period_key', $cursorPeriodKey)
                    ->lockForUpdate()
                    ->first();

                $createdNew = false;

                if (!$envelope) {
                    $envelope = Contribution::create([
                        ...$this->ownerPayload($userId, $beneficiaryId),
                        'financial_year_rule_id' => (int) $fy->id,
                        'period_key' => $cursorPeriodKey,
                        'amount' => 0,
                        'expected_date' => $expected,
                        'paid_date' => null,
                        'status' => 'paid',
                        'penalty_amount' => 0,
                        'recorded_by' => $recordedBy,
                    ]);
                    $createdNew = true;
                } else {
                    if (!$envelope->expected_date) {
                        $envelope->expected_date = $expected;
                    }
                }

                $beforeAmount = round((float) $envelope->amount, 2);
                $beforePaidDate = $envelope->paid_date
                    ? Carbon::parse($envelope->paid_date)->toDateString()
                    : null;
                $beforeStatus = (string) ($envelope->status ?? 'paid');
                $beforePenalty = round((float) ($envelope->penalty_amount ?? 0), 2);
                $beforeExpectedDate = $envelope->expected_date
                    ? Carbon::parse($envelope->expected_date)->toDateString()
                    : null;
                $beforeRecordedBy = (int) ($envelope->recorded_by ?? $recordedBy);

                $commitment = $this->commitmentService->activeForPeriod(
                    userId: $userId,
                    beneficiaryId: $beneficiaryId,
                    periodKey: $cursorPeriodKey
                );

                if (!$commitment && $strictCommitment) {
                    throw new InvalidArgumentException(
                        "No commitment set for this owner for period {$cursorPeriodKey}. Set the next cycle commitment before allocating further."
                    );
                }

                $monthlyTarget = $commitment
                    ? round((float) $commitment->amount, 2)
                    : round($beforeAmount + $remaining, 2);

                $needed = round(max(0, $monthlyTarget - $beforeAmount), 2);

                if ($needed <= 0) {
                    $cursorPeriodKey = $this->nextPeriodKey($cursorPeriodKey);
                    continue;
                }

                $alloc = round(min($remaining, $needed), 2);
                $afterAmount = round($beforeAmount + $alloc, 2);
                $remainingNeededAfter = round(max(0, $monthlyTarget - $afterAmount), 2);

                $status = $isLate ? 'late' : 'paid';

                $envelope->amount = $afterAmount;
                $envelope->paid_date = $paid;
                $envelope->status = $status;
                $envelope->recorded_by = $recordedBy;
                $envelope->save();

                $penaltyAppliedNow = false;
                $penaltyAmountAfter = round((float) ($envelope->penalty_amount ?? 0), 2);

                if ($isLate && $beforePenalty <= 0) {
                    $penalty = $this->penaltyService->contributionLate(
                        userId: $userId,
                        beneficiaryId: $beneficiaryId,
                        contributionId: $envelope->id,
                        recordedBy: $recordedBy,
                        periodKey: $cursorPeriodKey,
                        paidDate: $paid->toDateString(),
                        principalBase: $monthlyTarget
                    );

                    if ($penalty && (float) $penalty->amount > 0) {
                        $envelope->penalty_amount = (float) $penalty->amount;
                        $envelope->save();
                        $penaltyAppliedNow = true;
                        $penaltyAmountAfter = round((float) $envelope->penalty_amount, 2);
                    }
                }

                $tx = $this->ledger->record(
                    type: 'contribution',
                    debit: 0,
                    credit: $alloc,
                    userId: $userId,
                    beneficiaryId: $beneficiaryId,
                    reference: "[BATCH:{$batch->id}] Contribution Allocation (FY {$fy->year_key}, Period {$cursorPeriodKey}) ID {$envelope->id}",
                    createdBy: $recordedBy,
                    sourceType: 'contribution',
                    sourceId: $envelope->id
                );

                ContributionAllocation::create([
                    'contribution_batch_id' => $batch->id,
                    'contribution_id' => $envelope->id,
                    'transaction_id' => $tx->id ?? null,
                    'period_key' => $cursorPeriodKey,
                    'allocated_amount' => $alloc,
                    'before_amount' => $beforeAmount,
                    'after_amount' => $afterAmount,
                    'before_paid_date' => $beforePaidDate,
                    'after_paid_date' => $paid->toDateString(),
                    'before_status' => $beforeStatus,
                    'after_status' => $status,
                    'before_penalty_amount' => $beforePenalty,
                    'after_penalty_amount' => $penaltyAmountAfter,
                    'before_expected_date' => $beforeExpectedDate,
                    'after_expected_date' => $envelope->expected_date
                        ? Carbon::parse($envelope->expected_date)->toDateString()
                        : null,
                    'before_recorded_by' => $beforeRecordedBy,
                    'after_recorded_by' => $recordedBy,
                    'created_new' => $createdNew,
                    'penalty_applied_now' => $penaltyAppliedNow,
                ]);

                $allocations[] = [
                    'financial_year_rule_id' => (int) $fy->id,
                    'year_key' => (string) $fy->year_key,
                    'period_key' => $cursorPeriodKey,
                    'monthly_target' => $monthlyTarget,
                    'before_amount' => $beforeAmount,
                    'allocated' => $alloc,
                    'after_amount' => $afterAmount,
                    'remaining_needed_after' => $remainingNeededAfter,
                    'contribution_id' => (int) $envelope->id,
                    'status' => (string) $envelope->status,
                    'penalty_amount' => (float) ($envelope->penalty_amount ?? 0),
                    'expected_date' => $envelope->expected_date
                        ? Carbon::parse($envelope->expected_date)->toDateString()
                        : null,
                    'paid_date' => $paid->toDateString(),
                ];

                if ($cursorPeriodKey === $startPeriodKey) {
                    $startEnvelope = $envelope;
                }

                $remaining = round($remaining - $alloc, 2);

                if ($remaining > 0) {
                    $cursorPeriodKey = $this->nextPeriodKey($cursorPeriodKey);
                }
            }

            $start = ($startEnvelope ?: $this->ownerContributionQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', (int) $fy->id)
                ->where('period_key', $startPeriodKey)
                ->firstOrFail())
                ->refresh();

            return [
                'batch_id' => (int) $batch->id,
                'batch_ref' => $batchRef,
                'financial_year_rule_id' => (int) $fy->id,
                'year_key' => (string) $fy->year_key,
                'start' => $start,
                'allocations' => $allocations,
            ];
        });
    }

    public function undoRecordedBatch(array $undoPayload, int $actorId): array
    {
        $batchRef = (string) ($undoPayload['batch_ref'] ?? '');
        if (!$batchRef) {
            throw new InvalidArgumentException('batch_ref is required to undo.');
        }

        $fyId = (int) ($undoPayload['financial_year_rule_id'] ?? 0);
        if ($fyId <= 0) {
            throw new InvalidArgumentException('financial_year_rule_id is required to undo.');
        }

        $rows = $undoPayload['rows'] ?? [];
        if (!is_array($rows) || count($rows) === 0) {
            throw new InvalidArgumentException('Nothing to undo (rows missing).');
        }

        return DB::transaction(function () use ($rows, $batchRef, $fyId, $actorId) {
            $reverted = [];

            foreach ($rows as $r) {
                $contributionId = (int) ($r['contribution_id'] ?? 0);
                if ($contributionId <= 0) {
                    continue;
                }

                $before = (array) ($r['before'] ?? []);
                $createdNew = (bool) ($r['created_new'] ?? false);

                $env = Contribution::query()
                    ->where('id', $contributionId)
                    ->where('financial_year_rule_id', $fyId)
                    ->lockForUpdate()
                    ->first();

                if (!$env) {
                    continue;
                }

                $env->amount = (float) ($before['amount'] ?? 0);
                $env->paid_date = !empty($before['paid_date'])
                    ? Carbon::parse($before['paid_date'], $this->tz)->startOfDay()
                    : null;
                $env->status = (string) ($before['status'] ?? ($env->paid_date ? 'paid' : 'missed'));
                $env->penalty_amount = (float) ($before['penalty_amount'] ?? 0);

                if (!empty($before['expected_date']) && !$env->expected_date) {
                    $env->expected_date = Carbon::parse($before['expected_date'], $this->tz)->startOfDay();
                }

                $env->recorded_by = (int) ($before['recorded_by'] ?? $actorId);
                $env->save();

                if ((float) ($before['amount'] ?? 0) > 0) {
                    $this->ledger->record(
                        type: 'contribution_reversal',
                        debit: (float) ($r['allocated'] ?? 0),
                        credit: 0,
                        userId: $env->user_id,
                        beneficiaryId: $env->beneficiary_id,
                        reference: "[BATCH:$batchRef] Undo contribution allocation for envelope {$env->id}",
                        createdBy: $actorId,
                        sourceType: 'contribution',
                        sourceId: $env->id
                    );
                }

                if ($createdNew && (float) $env->amount <= 0 && $env->paid_date === null) {
                    $env->delete();
                }

                $reverted[] = $contributionId;
            }

            return [
                'batch_ref' => $batchRef,
                'reverted_contribution_ids' => $reverted,
            ];
        });
    }

    public function markMissed(
        int $userId,
        ?int $beneficiaryId,
        string $expectedDate,
        int $recordedBy
    ): Contribution {
        $this->validateOwner($userId, $beneficiaryId);

        $expected = Carbon::parse($expectedDate, $this->tz)->startOfDay();
        $periodKey = $expected->format('Y-m');

        return $this->markMissedByPeriod(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            period: $periodKey,
            recordedBy: $recordedBy,
            expectedDate: $expected->toDateString(),
            financialYearRuleId: null
        );
    }

    public function markMissedByPeriod(
        int $userId,
        ?int $beneficiaryId,
        string $period,
        int $recordedBy,
        ?string $expectedDate = null,
        ?int $financialYearRuleId = null
    ): Contribution {
        $this->validateOwner($userId, $beneficiaryId);

        $fy = $this->resolveFy($financialYearRuleId);
        $this->ensureOwnerFyOpen($userId, $beneficiaryId, (int) $fy->id);

        return DB::transaction(function () use ($userId, $beneficiaryId, $period, $recordedBy, $expectedDate, $fy) {
            $periodKey = Carbon::createFromFormat('Y-m', $period, $this->tz)->format('Y-m');

            $this->assertPeriodInFY($periodKey, $fy);

            $commitment = $this->commitmentService->activeForPeriod(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                periodKey: $periodKey
            );

            if (!$commitment) {
                throw new InvalidArgumentException(
                    "No commitment set for this owner for period {$periodKey}. Cannot mark missed."
                );
            }

            $expected = $expectedDate
                ? Carbon::parse($expectedDate, $this->tz)->startOfDay()
                : $this->computeExpectedDateFromFyRule($fy, $periodKey);

            $existing = $this->ownerContributionQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', (int) $fy->id)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $contribution = Contribution::create([
                ...$this->ownerPayload($userId, $beneficiaryId),
                'financial_year_rule_id' => (int) $fy->id,
                'amount' => 0,
                'period_key' => $periodKey,
                'expected_date' => $expected,
                'paid_date' => null,
                'status' => 'missed',
                'penalty_amount' => 0,
                'recorded_by' => $recordedBy,
            ]);

            $penalty = $this->penaltyService->contributionMissed(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                contributionId: $contribution->id,
                recordedBy: $recordedBy,
                periodKey: $periodKey,
                principalBase: (float) $commitment->amount,
                date: $expected->toDateString()
            );

            if ($penalty && (float) $penalty->amount > 0) {
                $contribution->update(['penalty_amount' => (float) $penalty->amount]);
            }

            return $contribution->refresh();
        });
    }

    public function totalContributionsForOwner(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null
    ): float {
        $this->validateOwner($userId, $beneficiaryId);

        $fyId = $financialYearRuleId ?: (int) FinancialYearRule::where('is_active', true)->value('id');
        if (!$fyId) {
            throw new InvalidArgumentException('No active financial year found.');
        }

        return (float) $this->ownerContributionQuery($userId, $beneficiaryId)
            ->where('financial_year_rule_id', $fyId)
            ->sum('amount');
    }

    public function contributedMonthsCount(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null
    ): int {
        $this->validateOwner($userId, $beneficiaryId);

        $fyId = $financialYearRuleId ?: (int) FinancialYearRule::where('is_active', true)->value('id');
        if (!$fyId) {
            throw new InvalidArgumentException('No active financial year found.');
        }

        return (int) $this->ownerContributionQuery($userId, $beneficiaryId)
            ->where('financial_year_rule_id', $fyId)
            ->select('period_key')
            ->distinct()
            ->count('period_key');
    }

    public function openingBalanceForOwner(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null
    ): float {
        $this->validateOwner($userId, $beneficiaryId);

        $fyId = $financialYearRuleId ?: (int) FinancialYearRule::where('is_active', true)->value('id');
        if (!$fyId) {
            throw new InvalidArgumentException('No active financial year found.');
        }

        $mfy = MemberFinancialYear::firstOrCreate(
            [
                'user_id' => $userId,
                'beneficiary_id' => $beneficiaryId,
                'financial_year_rule_id' => $fyId,
            ],
            [
                'opening_balance' => 0,
                'commitment_amount' => 0,
            ]
        );

        return (float) $mfy->opening_balance;
    }

    public function savingsBaseForLoanLimit(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null
    ): float {
        $opening = $this->openingBalanceForOwner($userId, $beneficiaryId, $financialYearRuleId);
        $contrib = $this->totalContributionsForOwner($userId, $beneficiaryId, $financialYearRuleId);

        return round($opening + $contrib, 2);
    }

    public function recordSinglePeriod(
        int $userId,
        ?int $beneficiaryId,
        float $amount,
        ?string $expectedDate,
        string $paidDate,
        int $recordedBy,
        ?string $period = null,
        ?int $financialYearRuleId = null
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        if (!$period) {
            throw new InvalidArgumentException('period (YYYY-MM) is required.');
        }

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new InvalidArgumentException('period must be in YYYY-MM format.');
        }

        $fy = $this->resolveFy($financialYearRuleId);
        $this->ensureOwnerFyOpen($userId, $beneficiaryId, (int) $fy->id);

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Amount must be greater than 0.');
        }

        return DB::transaction(function () use ($userId, $beneficiaryId, $amount, $expectedDate, $paidDate, $recordedBy, $period, $fy) {
            $rules = SystemRule::firstOrFail();

            $min = (float) ($rules->contribution_min_amount ?? 0);
            if ($amount < $min) {
                throw new InvalidArgumentException("Amount cannot be below minimum ({$min}).");
            }

            $paid = Carbon::parse($paidDate, $this->tz)->startOfDay();
            $periodKey = Carbon::createFromFormat('Y-m', $period, $this->tz)->format('Y-m');

            $this->assertPeriodInFY($periodKey, $fy);

            $commitment = $this->commitmentService->activeForPeriod(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                periodKey: $periodKey
            );

            if (!$commitment) {
                throw new InvalidArgumentException(
                    "No commitment set for this owner for period {$periodKey}. Set the commitment for this month before recording."
                );
            }

            $monthlyTarget = (float) $commitment->amount;

            $expected = $expectedDate
                ? Carbon::parse($expectedDate, $this->tz)->startOfDay()
                : $this->computeExpectedDateFromFyRule($fy, $periodKey);

            $isLate = $paid->gt($expected);

            $envelope = $this->ownerContributionQuery($userId, $beneficiaryId)
                ->where('financial_year_rule_id', (int) $fy->id)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if (!$envelope) {
                $envelope = Contribution::create([
                    ...$this->ownerPayload($userId, $beneficiaryId),
                    'financial_year_rule_id' => (int) $fy->id,
                    'period_key' => $periodKey,
                    'amount' => 0,
                    'expected_date' => $expected,
                    'paid_date' => null,
                    'status' => 'paid',
                    'penalty_amount' => 0,
                    'recorded_by' => $recordedBy,
                ]);
            } else {
                if (!$envelope->expected_date) {
                    $envelope->expected_date = $expected;
                }
            }

            $beforeAmount = (float) $envelope->amount;
            $afterAmount = $beforeAmount + $amount;
            $status = $isLate ? 'late' : 'paid';

            $envelope->amount = $afterAmount;
            $envelope->paid_date = $paid;
            $envelope->status = $status;
            $envelope->recorded_by = $recordedBy;
            $envelope->save();

            $this->ledger->record(
                type: 'contribution',
                debit: 0,
                credit: $amount,
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                reference: 'Contribution (FY ' . $fy->year_key . ', Period ' . $periodKey . ') ID ' . $envelope->id,
                createdBy: $recordedBy,
                sourceType: 'contribution',
                sourceId: $envelope->id
            );

            if ($isLate && (float) ($envelope->penalty_amount ?? 0) <= 0) {
                $penalty = $this->penaltyService->contributionLate(
                    userId: $userId,
                    beneficiaryId: $beneficiaryId,
                    contributionId: $envelope->id,
                    recordedBy: $recordedBy,
                    periodKey: $periodKey,
                    paidDate: $paid->toDateString(),
                    principalBase: $monthlyTarget
                );

                if ($penalty && (float) $penalty->amount > 0) {
                    $envelope->penalty_amount = (float) $penalty->amount;
                    $envelope->save();
                }
            }

            $remainingNeededAfter = max(0, $monthlyTarget - $afterAmount);

            return [
                'financial_year_rule_id' => (int) $fy->id,
                'year_key' => (string) $fy->year_key,
                'start' => $envelope->refresh(),
                'allocations' => [[
                    'financial_year_rule_id' => (int) $fy->id,
                    'year_key'               => (string) $fy->year_key,
                    'period_key'             => $periodKey,
                    'monthly_target'         => $monthlyTarget,
                    'before_amount'          => round((float) $beforeAmount, 2),
                    'allocated'              => round((float) $amount, 2),
                    'after_amount'           => round((float) $afterAmount, 2),
                    'remaining_needed_after' => round((float) $remainingNeededAfter, 2),
                    'contribution_id'        => (int) $envelope->id,
                    'status'                 => (string) $envelope->status,
                    'penalty_amount'         => (float) ($envelope->penalty_amount ?? 0),
                    'expected_date'          => $envelope->expected_date ? Carbon::parse($envelope->expected_date)->toDateString() : null,
                    'paid_date'              => $paid->toDateString(),
                ]],
            ];
        });
    }

    public function contributedMonthsCountFromContributions(
        int $userId,
        ?int $beneficiaryId = null
    ): int {
        $this->validateOwner($userId, $beneficiaryId);

        return (int) $this->ownerContributionQuery($userId, $beneficiaryId)
            ->whereIn('status', ['paid', 'late'])
            ->whereNotNull('paid_date')
            ->select('period_key')
            ->distinct()
            ->count();
    }

    public function undoBatch(int $batchId, int $reversedBy): array
    {
        return DB::transaction(function () use ($batchId, $reversedBy) {
            $batch = ContributionBatch::with([
                'allocations.contribution',
                'allocations.transaction',
            ])->lockForUpdate()->findOrFail($batchId);

            if ($batch->reversed_at) {
                throw new InvalidArgumentException('This contribution batch was already reversed.');
            }

            $this->ensureOwnerFyOpen((int) $batch->user_id, $batch->beneficiary_id, (int) $batch->financial_year_rule_id);

            $reversedCount = 0;
            $reversedAmount = 0.0;
            $deletedContributions = 0;
            $restoredContributions = 0;

            foreach ($batch->allocations()->orderByDesc('id')->get() as $allocation) {
                $contribution = Contribution::lockForUpdate()->find($allocation->contribution_id);

                if (!$contribution) {
                    continue;
                }

                if ($allocation->transaction_id) {
                    $tx = Transaction::lockForUpdate()->find($allocation->transaction_id);

                    if ($tx) {
                        $reverseDebit = (float) $tx->credit;
                        $reverseCredit = (float) $tx->debit;

                        if ($reverseDebit > 0 || $reverseCredit > 0) {
                            $this->ledger->record(
                                type: 'contribution_reversal',
                                debit: $reverseDebit,
                                credit: $reverseCredit,
                                userId: (int) $tx->user_id,
                                beneficiaryId: $tx->beneficiary_id,
                                reference: 'Reversal of transaction ID ' . $tx->id . ' from contribution batch ID ' . $batch->id,
                                createdBy: $reversedBy,
                                sourceType: 'contribution_batch_reversal',
                                sourceId: $batch->id
                            );
                        }
                    }
                }

                if ((bool) $allocation->penalty_applied_now) {
                    $this->reverseContributionPenalty(
                        contributionId: (int) $contribution->id,
                        reversedBy: $reversedBy,
                        batchId: (int) $batch->id
                    );
                }

                if ((bool) $allocation->created_new && (float) $allocation->before_amount <= 0) {
                    $contribution->delete();
                    $deletedContributions++;
                } else {
                    $contribution->amount = (float) $allocation->before_amount;
                    $contribution->paid_date = $allocation->before_paid_date;
                    $contribution->status = $allocation->before_status ?: 'paid';
                    $contribution->penalty_amount = (float) $allocation->before_penalty_amount;

                    if ($allocation->before_expected_date) {
                        $contribution->expected_date = $allocation->before_expected_date;
                    }

                    $contribution->recorded_by = $allocation->before_recorded_by ?: $contribution->recorded_by;
                    $contribution->save();

                    $restoredContributions++;
                }

                $reversedCount++;
                $reversedAmount += (float) $allocation->allocated_amount;
            }

            $batch->update([
                'reversed_at' => now($this->tz),
                'reversed_by' => $reversedBy,
            ]);

            return [
                'batch_id' => (int) $batch->id,
                'user_id' => (int) $batch->user_id,
                'beneficiary_id' => $batch->beneficiary_id,
                'financial_year_rule_id' => (int) $batch->financial_year_rule_id,
                'reversed_allocations' => $reversedCount,
                'reversed_amount' => round($reversedAmount, 2),
                'deleted_contributions' => $deletedContributions,
                'restored_contributions' => $restoredContributions,
                'reversed_at' => $batch->reversed_at?->toDateTimeString(),
            ];
        });
    }

    public function undoLastBatchForOwner(
        int $userId,
        ?int $beneficiaryId = null,
        ?int $financialYearRuleId = null,
        int $reversedBy = 0
    ): array {
        $this->validateOwner($userId, $beneficiaryId);

        $q = ContributionBatch::query()
            ->where('user_id', $userId)
            ->whereNull('reversed_at');

        if (is_null($beneficiaryId)) {
            $q->whereNull('beneficiary_id');
        } else {
            $q->where('beneficiary_id', $beneficiaryId);
        }

        if ($financialYearRuleId) {
            $q->where('financial_year_rule_id', $financialYearRuleId);
        }

        $batch = $q->latest('id')->first();

        if (!$batch) {
            throw new InvalidArgumentException('No unreversed contribution batch found for this owner.');
        }

        return $this->undoBatch((int) $batch->id, $reversedBy);
    }

    private function reverseContributionPenalty(int $contributionId, int $reversedBy, int $batchId): void
    {
        $penalties = Penalty::query()
            ->where('contribution_id', $contributionId)
            ->whereIn('status', ['unpaid', 'paid'])
            ->lockForUpdate()
            ->get();

        foreach ($penalties as $penalty) {
            $oldAmount = (float) $penalty->amount;

            if ($oldAmount <= 0) {
                continue;
            }

            $penaltyTx = Transaction::query()
                ->where('source_type', 'penalty')
                ->where('source_id', $penalty->id)
                ->where('type', 'penalty')
                ->latest('id')
                ->first();

            if ($penaltyTx) {
                $reverseDebit = (float) $penaltyTx->credit;
                $reverseCredit = (float) $penaltyTx->debit;

                if ($reverseDebit > 0 || $reverseCredit > 0) {
                    $this->ledger->record(
                        type: 'penalty_reversal',
                        debit: $reverseDebit,
                        credit: $reverseCredit,
                        userId: (int) $penalty->user_id,
                        beneficiaryId: $penalty->beneficiary_id,
                        reference: 'Reversal of penalty ID ' . $penalty->id . ' from contribution batch ID ' . $batchId,
                        createdBy: $reversedBy,
                        sourceType: 'penalty_reversal',
                        sourceId: $penalty->id
                    );
                }
            }

            $penalty->update([
                'status' => 'waived',
                'amount' => 0,
            ]);
        }
    }

    private function resolveFy(?int $financialYearRuleId): FinancialYearRule
    {
        return $financialYearRuleId
            ? FinancialYearRule::findOrFail($financialYearRuleId)
            : FinancialYearRule::where('is_active', true)->firstOrFail();
    }

    private function ensureOwnerFyOpen(int $userId, ?int $beneficiaryId, int $fyId): MemberFinancialYear
    {
        $this->validateOwner($userId, $beneficiaryId);

        $mfy = $this->memberFinancialYearService->getOrCreate(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            financialYearRuleId: $fyId
        );

        if ($mfy->closed_at) {
            throw new InvalidArgumentException('This financial year is closed for this owner.');
        }

        return $mfy;
    }

    private function computeExpectedDateFromFyRule(FinancialYearRule $fy, string $periodKey): Carbon
    {
        $offset = (int) ($fy->due_month_offset ?? 0);
        $dueDay = (int) ($fy->due_day ?? 25);
        $grace = (int) ($fy->grace_days ?? 0);

        $base = Carbon::createFromFormat('Y-m-d', $periodKey . '-01', $this->tz)->startOfDay();
        $dueMonth = $base->copy()->addMonths($offset);
        $lastDay = $dueMonth->copy()->endOfMonth()->day;

        $expected = $dueMonth->copy()
            ->day(min($dueDay, $lastDay))
            ->startOfDay();

        if ($grace > 0) {
            $expected = $expected->addDays($grace);
        }

        return $expected;
    }

    private function nextPeriodKey(string $periodKey): string
    {
        return Carbon::createFromFormat('Y-m', $periodKey, $this->tz)
            ->startOfMonth()
            ->addMonth()
            ->format('Y-m');
    }

    private function assertPeriodInFY(string $periodKey, FinancialYearRule $fy): void
    {
        $monthStart = Carbon::createFromFormat('Y-m', $periodKey, $this->tz)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        if ($monthEnd->lt($fy->start_date) || $monthStart->gt($fy->end_date)) {
            throw new InvalidArgumentException("Period {$periodKey} is outside financial year {$fy->year_key}.");
        }
    }

    private function validateOwner(int $userId, ?int $beneficiaryId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required and must be a valid positive integer.');
        }

        if (!is_null($beneficiaryId) && $beneficiaryId <= 0) {
            throw new InvalidArgumentException('beneficiary_id must be a valid positive integer when provided.');
        }
    }

    private function ownerContributionQuery(int $userId, ?int $beneficiaryId): Builder
    {
        $this->validateOwner($userId, $beneficiaryId);

        $query = Contribution::query()->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            $query->whereNull('beneficiary_id');
        } else {
            $query->where('beneficiary_id', $beneficiaryId);
        }

        return $query;
    }

    private function ownerPayload(int $userId, ?int $beneficiaryId): array
    {
        $this->validateOwner($userId, $beneficiaryId);

        return [
            'user_id' => $userId,
            'beneficiary_id' => $beneficiaryId,
        ];
    }
}
