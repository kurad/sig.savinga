<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\SystemRule;
use App\Models\Contribution;
use App\Models\OpeningBalance;
use App\Models\Beneficiary;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\CommitmentService;
use App\Services\ContributionService;
use App\Services\ContributionReportService;
use App\Http\Requests\Contributions\StoreContributionRequest;
use App\Http\Requests\Contributions\BulkStoreContributionsRequest;
use App\Http\Requests\Contributions\MarkMissedContributionRequest;
use App\Http\Requests\Contributions\BulkContributionPreviewRequest;

class ContributionController extends Controller
{
    public function __construct(
        protected ContributionService $contributionService,
        protected ContributionReportService $reportService,
        protected CommitmentService $commitmentService,
    ) {}

    protected function contributionRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
        ];
    }

    protected function resolveParticipantFromRequest(Request $request): array
    {
        return [
            'userId' => (int) $request->input('user_id'),
            'beneficiaryId' => $request->filled('beneficiary_id')
                ? (int) $request->input('beneficiary_id')
                : null,
        ];
    }
    public function index(Request $request)
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'status' => ['nullable', 'string'],
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $data = $this->reportService->list(
            filters: $filters,
            perPage: 15
        );

        return response()->json($data);
    }
    public function store(StoreContributionRequest $request)
    {
        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveParticipantFromRequest($request);

        $expectedDate = $request->input('expected_date');
        if ($expectedDate === '') {
            $expectedDate = null;
        }

        $result = $this->contributionService->record(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            amount: (float) $request->input('amount'),
            expectedDate: $expectedDate,
            paidDate: $request->input('paid_date'),
            recordedBy: (int) $request->user()->id,
            period: $request->input('period')
        );

        return response()->json([
            'message' => 'Contribution recorded successfully.',
            'batch_id' => $result['batch_id'] ?? null,
            'data' => $result['start']->load($this->contributionRelations()),
            'allocations' => $result['allocations'] ?? [],
        ], 201);
    }

    public function undo(Request $request)
    {
        $request->validate([
            'undo' => ['required', 'array'],
        ]);

        $res = $this->contributionService->undoRecordedBatch(
            undoPayload: (array) $request->input('undo'),
            actorId: (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Contribution batch undone.',
            'data' => $res,
        ]);
    }

    /**
     * Mark missed contribution
     * POST /api/contributions/missed
     */
    public function markMissed(MarkMissedContributionRequest $request)
    {
        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveParticipantFromRequest($request);

        $expectedDate = $request->input('expected_date');
        $period = $request->input('period');

        if ($expectedDate) {
            $contribution = $this->contributionService->markMissed(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                expectedDate: $expectedDate,
                recordedBy: (int) $request->user()->id
            );
        } else {
            $contribution = $this->contributionService->markMissedByPeriod(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                period: $period,
                recordedBy: (int) $request->user()->id
            );
        }

        return response()->json([
            'message' => 'Contribution marked as missed.',
            'data' => $contribution->load($this->contributionRelations()),
        ], 201);
    }

    /**
     * Still user-based until report service is reviewed for beneficiaries too.
     */
    public function memberSummary(Request $request, User $user)
    {
        try {
            $data = $this->reportService->memberSummary(
                viewer: $request->user(),
                member: $user,
                from: $request->query('from'),
                to: $request->query('to'),
            );

            return response()->json($data);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
    public function bulkPreview(BulkContributionPreviewRequest $request)
    {
        $period = $request->query('period');
        $periodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');
        $rules = SystemRule::firstOrFail();

        $ownerType = $request->query('owner_type', 'user');

        if ($ownerType === 'beneficiary') {
            $beneficiariesQuery = Beneficiary::query()
                ->select(['id', 'guardian_user_id', 'name', 'relationship', 'is_active']);

            if (!$request->boolean('include_inactive')) {
                $beneficiariesQuery->where('is_active', true);
            }

            $beneficiaries = $beneficiariesQuery
                ->with('guardian:id,name,email,phone')
                ->orderBy('name')
                ->get();

            $openingByBeneficiary = OpeningBalance::query()
                ->whereIn('beneficiary_id', $beneficiaries->pluck('id'))
                ->get()
                ->keyBy('beneficiary_id');

            $existingByBeneficiary = Contribution::query()
                ->where('period_key', $periodKey)
                ->whereIn('beneficiary_id', $beneficiaries->pluck('id'))
                ->get()
                ->keyBy('beneficiary_id');

            $expectedDate = $this->contributionServiceExpectedFromRules($rules, $periodKey);

            $rows = $beneficiaries->map(function ($b) use ($periodKey, $existingByBeneficiary, $expectedDate, $openingByBeneficiary) {
                $env = $existingByBeneficiary->get($b->id);
                $commitment = $this->commitmentService->activeForPeriod((int) $b->guardian_user_id, (int) $b->id, $periodKey);
                $opening = $openingByBeneficiary->get($b->id);

                $openingSet = (bool) $opening;
                $canProcess = true;
                $reason = null;

                if (!$openingSet) {
                    $canProcess = false;
                    $reason = 'Opening capital not set.';
                }

                if (!$commitment) {
                    $canProcess = false;
                    $reason = 'No commitment set for this period.';
                }

                if ($env && in_array($env->status, ['paid', 'late'], true)) {
                    $reason = "Already recorded ({$env->status}). Will be skipped.";
                }

                return [
                    'owner_type' => 'beneficiary',
                    'user_id' => $b->guardian_user_id,
                    'beneficiary' => [
                        'id' => $b->id,
                        'name' => $b->name,
                        'relationship' => $b->relationship,
                        'guardian' => $b->guardian ? [
                            'id' => $b->guardian->id,
                            'name' => $b->guardian->name,
                            'email' => $b->guardian->email,
                            'phone' => $b->guardian->phone,
                        ] : null,
                    ],
                    'period' => $periodKey,
                    'opening_balance_set' => $openingSet,
                    'opening_balance' => $opening ? (float) $opening->amount : null,
                    'opening_as_of' => $opening?->as_of_period,
                    'commitment_amount' => $commitment ? (float) $commitment->amount : null,
                    'expected_date' => $expectedDate,
                    'can_process' => $canProcess,
                    'reason' => $reason,
                    'existing' => $env ? [
                        'id' => $env->id,
                        'amount' => (float) $env->amount,
                        'status' => $env->status,
                        'paid_date' => $env->paid_date,
                        'penalty_amount' => (float) $env->penalty_amount,
                    ] : null,
                ];
            });

            return response()->json([
                'owner_type' => 'beneficiary',
                'period' => $periodKey,
                'expected_date_default' => $expectedDate,
                'rows' => $rows,
            ]);
        }

        $membersQuery = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role']);

        if (!$request->boolean('include_inactive')) {
            // $membersQuery->where('is_active', 1);
        }

        $members = $membersQuery->orderBy('name')->get();

        $openingByUser = OpeningBalance::query()
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $existingByUser = Contribution::query()
            ->where('period_key', $periodKey)
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $expectedDate = $this->contributionServiceExpectedFromRules($rules, $periodKey);

        $rows = $members->map(function ($m) use ($periodKey, $existingByUser, $expectedDate, $openingByUser) {
            $env = $existingByUser->get($m->id);
            $commitment = $this->commitmentService->activeForPeriod((int) $m->id, null, $periodKey);
            $opening = $openingByUser->get($m->id);

            $openingSet = (bool) $opening;
            $canProcess = true;
            $reason = null;

            if (!$openingSet) {
                $canProcess = false;
                $reason = 'Opening capital not set.';
            }

            if (!$commitment) {
                $canProcess = false;
                $reason = 'No commitment set for this period.';
            }

            if ($env && in_array($env->status, ['paid', 'late'], true)) {
                $reason = "Already recorded ({$env->status}). Will be skipped.";
            }

            return [
                'owner_type' => 'user',
                'user' => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'phone' => $m->phone,
                    'role' => $m->role,
                ],
                'period' => $periodKey,
                'opening_balance_set' => $openingSet,
                'opening_balance' => $opening ? (float) $opening->amount : null,
                'opening_as_of' => $opening?->as_of_period,
                'commitment_amount' => $commitment ? (float) $commitment->amount : null,
                'expected_date' => $expectedDate,
                'can_process' => $canProcess,
                'reason' => $reason,
                'existing' => $env ? [
                    'id' => $env->id,
                    'amount' => (float) $env->amount,
                    'status' => $env->status,
                    'paid_date' => $env->paid_date,
                    'penalty_amount' => (float) $env->penalty_amount,
                ] : null,
            ];
        });

        return response()->json([
            'owner_type' => 'user',
            'period' => $periodKey,
            'expected_date_default' => $expectedDate,
            'rows' => $rows,
        ]);
    }

    private function contributionServiceExpectedFromRules(SystemRule $rules, string $periodKey): string
    {
        $dueDay = (int) ($rules->contribution_due_day ?? 25);

        $first = Carbon::createFromFormat('Y-m-d', $periodKey . '-01')->startOfDay();
        $lastDay = $first->copy()->endOfMonth()->day;

        return $first->copy()->day(min($dueDay, $lastDay))->format('Y-m-d');
    }

    public function bulkStore(BulkStoreContributionsRequest $request)
    {
        $period = $request->input('period');
        $periodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');

        $defaultPaidDate = $request->input('paid_date');
        $defaultExpected = $request->input('expected_date');
        $defaultOwnerType = $request->input('owner_type', 'user');

        $items = $request->input('items', []);
        $recordedBy = (int) $request->user()->id;

        $results = [
            'period' => $periodKey,
            'success' => [],
            'errors' => [],
            'totals' => [
                'rows' => count($items),
                'ok' => 0,
                'failed' => 0,
                'skipped' => 0,
                'amount_sum' => 0,
                'missed_count' => 0,
            ],
        ];

        $userIds = collect($items)
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->map(fn ($v) => (int) $v)
            ->values();

        $beneficiaryIds = collect($items)
            ->pluck('beneficiary_id')
            ->filter()
            ->unique()
            ->map(fn ($v) => (int) $v)
            ->values();

        $existingByUser = Contribution::query()
            ->where('period_key', $periodKey)
            ->when($userIds->isNotEmpty(), fn ($q) => $q->whereIn('user_id', $userIds))
            ->whereNull('beneficiary_id')
            ->get()
            ->keyBy('user_id');

        $existingByBeneficiary = Contribution::query()
            ->where('period_key', $periodKey)
            ->when($beneficiaryIds->isNotEmpty(), fn ($q) => $q->whereIn('beneficiary_id', $beneficiaryIds))
            ->get()
            ->keyBy('beneficiary_id');

        $openingByUser = OpeningBalance::query()
            ->when($userIds->isNotEmpty(), fn ($q) => $q->whereIn('user_id', $userIds))
            ->whereNull('beneficiary_id')
            ->get()
            ->keyBy('user_id');

        $openingByBeneficiary = OpeningBalance::query()
            ->when($beneficiaryIds->isNotEmpty(), fn ($q) => $q->whereIn('beneficiary_id', $beneficiaryIds))
            ->get()
            ->keyBy('beneficiary_id');

        foreach ($items as $row) {
            $ownerType = $row['owner_type'] ?? $defaultOwnerType;

            $userId = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            $beneficiaryId = !empty($row['beneficiary_id']) ? (int) $row['beneficiary_id'] : null;

            $amount = (float) ($row['amount'] ?? 0);
            $missed = (bool) ($row['missed'] ?? false);
            $paidDate = $row['paid_date'] ?? $defaultPaidDate;
            $expected = $row['expected_date'] ?? $defaultExpected;

            try {
                if (!$userId) {
                    throw new \InvalidArgumentException('user_id is required.');
                }

                if ($ownerType === 'beneficiary' && !$beneficiaryId) {
                    throw new \InvalidArgumentException('beneficiary_id is required for beneficiary rows.');
                }

                if ($ownerType === 'user') {
                    $beneficiaryId = null;
                }

                $existing = $ownerType === 'user'
                    ? $existingByUser->get($userId)
                    : $existingByBeneficiary->get($beneficiaryId);

                $hadExisting = (bool) $existing;

                $opening = $ownerType === 'user'
                    ? $openingByUser->get($userId)
                    : $openingByBeneficiary->get($beneficiaryId);

                if (!$opening) {
                    $results['success'][] = [
                        'owner_type' => $ownerType,
                        'user_id' => $userId,
                        'beneficiary_id' => $beneficiaryId,
                        'action' => 'skipped_no_opening_balance',
                        'period_key' => $periodKey,
                        'message' => 'Opening capital not set.',
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }

                if (!empty($opening->as_of_period) && $periodKey < $opening->as_of_period) {
                    $results['success'][] = [
                        'owner_type' => $ownerType,
                        'user_id' => $userId,
                        'beneficiary_id' => $beneficiaryId,
                        'action' => 'skipped_before_opening_period',
                        'period_key' => $periodKey,
                        'opening_as_of' => $opening->as_of_period,
                        'message' => 'Period is before opening capital as-of period.',
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }

                $commitment = $this->commitmentService->activeForPeriod($userId, $beneficiaryId, $periodKey);

                if (!$commitment) {
                    $results['success'][] = [
                        'owner_type' => $ownerType,
                        'user_id' => $userId,
                        'beneficiary_id' => $beneficiaryId,
                        'action' => 'skipped_no_commitment',
                        'period_key' => $periodKey,
                        'message' => 'No commitment set for this period.',
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }

                $monthlyTarget = (float) $commitment->amount;

                if ($missed || $amount <= 0) {
                    if ($existing && in_array($existing->status, ['paid', 'late'], true) && (float) $existing->amount >= $monthlyTarget) {
                        $results['success'][] = [
                            'owner_type' => $ownerType,
                            'user_id' => $userId,
                            'beneficiary_id' => $beneficiaryId,
                            'action' => 'skipped_missed_already_paid',
                            'period_key' => $periodKey,
                            'existing_contribution_id' => $existing->id,
                            'existing_status' => $existing->status,
                            'existing_amount' => (float) $existing->amount,
                            'target' => $monthlyTarget,
                        ];
                        $results['totals']['ok']++;
                        $results['totals']['skipped']++;
                        continue;
                    }

                    if ($existing && $existing->status !== 'missed') {
                        throw new \InvalidArgumentException(
                            "Cannot mark missed: existing contribution already exists for {$periodKey} (status {$existing->status}, amount {$existing->amount})."
                        );
                    }

                    $c = $this->contributionService->markMissedByPeriod(
                        userId: $userId,
                        beneficiaryId: $beneficiaryId,
                        period: $periodKey,
                        recordedBy: $recordedBy,
                        expectedDate: $expected
                    );

                    $results['success'][] = [
                        'owner_type' => $ownerType,
                        'user_id' => $userId,
                        'beneficiary_id' => $beneficiaryId,
                        'action' => $existing ? 'missed_exists' : 'missed',
                        'contribution_id' => $c->id,
                        'period_key' => $c->period_key,
                        'status' => $c->status,
                    ];

                    $results['totals']['ok']++;
                    $results['totals']['missed_count']++;

                    if ($ownerType === 'user') {
                        $existingByUser->put($userId, $c);
                    } else {
                        $existingByBeneficiary->put($beneficiaryId, $c);
                    }

                    continue;
                }

                if ($existing && (float) $existing->amount >= $monthlyTarget) {
                    $results['success'][] = [
                        'owner_type' => $ownerType,
                        'user_id' => $userId,
                        'beneficiary_id' => $beneficiaryId,
                        'action' => 'skipped_fully_covered',
                        'period_key' => $periodKey,
                        'existing_contribution_id' => $existing->id,
                        'existing_amount' => (float) $existing->amount,
                        'target' => $monthlyTarget,
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }

                if (!$paidDate) {
                    throw new \InvalidArgumentException("paid_date is required for paid rows (user {$userId}).");
                }

                $res = $this->contributionService->record(
                    userId: $userId,
                    beneficiaryId: $beneficiaryId,
                    amount: $amount,
                    expectedDate: $expected,
                    paidDate: $paidDate,
                    recordedBy: $recordedBy,
                    period: $periodKey
                );

                $start = $res['start'] ?? null;

                $results['success'][] = [
                    'owner_type' => $ownerType,
                    'user_id' => $userId,
                    'beneficiary_id' => $beneficiaryId,
                    'action' => $hadExisting ? 'topped_up_or_allocated' : 'paid',
                    'amount' => $amount,
                    'start_contribution_id' => $start?->id,
                    'period_key' => $periodKey,
                    'allocations' => $res['allocations'] ?? [],
                ];

                $results['totals']['ok']++;
                $results['totals']['amount_sum'] += $amount;

                if ($start) {
                    if ($ownerType === 'user') {
                        $existingByUser->put($userId, $start);
                    } else {
                        $existingByBeneficiary->put($beneficiaryId, $start);
                    }
                }
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'owner_type' => $ownerType,
                    'user_id' => $userId,
                    'beneficiary_id' => $beneficiaryId,
                    'period_key' => $periodKey,
                    'message' => $e->getMessage(),
                ];
                $results['totals']['failed']++;
            }
        }

        return response()->json([
            'message' => 'Bulk processing completed.',
            'data' => $results,
        ], 200);
    }

    /**
     * Preview a single contribution allocation.
     * No owner_type needed.
     */
    public function preview(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_date' => ['required', 'date'],
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'expected_date' => ['nullable', 'date'],
            'strict_commitment' => ['nullable', 'boolean'],
            'bypass_min' => ['nullable', 'boolean'],
            'financial_year_rule_id' => ['nullable', 'integer', 'exists:financial_year_rules,id'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveParticipantFromRequest($request);

        $expectedDate = $request->input('expected_date') ?: null;

        $res = $this->contributionService->previewAllocation(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            amount: (float) $request->input('amount'),
            expectedDate: $expectedDate,
            paidDate: (string) $request->input('paid_date'),
            period: $request->input('period'),
            strictCommitment: (bool) $request->boolean('strict_commitment', true),
            bypassMin: (bool) $request->boolean('bypass_min', false),
            financialYearRuleId: $request->input('financial_year_rule_id')
        );

        return response()->json([
            'message' => 'Contribution preview.',
            'data' => $res,
        ]);
    }

    public function undoBatch(Request $request, int $batchId)
    {
        try {
            $result = $this->contributionService->undoBatch(
                batchId: $batchId,
                reversedBy: (int) $request->user()->id
            );

            return response()->json([
                'message' => 'Contribution batch reversed successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    public function undoLast(Request $request)
    {
        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'financial_year_rule_id' => ['nullable', 'integer', 'exists:financial_year_rules,id'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveParticipantFromRequest($request);

        try {
            $result = $this->contributionService->undoLastBatchForOwner(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                financialYearRuleId: $request->input('financial_year_rule_id')
                    ? (int) $request->input('financial_year_rule_id')
                    : null,
                reversedBy: (int) $request->user()->id
            );

            return response()->json([
                'message' => 'Last contribution batch reversed successfully.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}