<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Penalty;
use App\Models\SystemRule;
use App\Models\Contribution;
use Illuminate\Http\Request;
use App\Models\OpeningBalance;
use App\Services\CommitmentService;
use App\Http\Controllers\Controller;
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

    /**
     * List contributions (filters supported)
     * GET /api/contributions?user_id=&from=&to=&status=
     */
    public function index(Request $request)
    {
        $data = $this->reportService->list(
            filters: $request->only([
                'user_id',
                'status',
                'period',
                'from',
                'to'
            ]),
            perPage: 15
        );

        return response()->json($data);
    }
    /**
     * Record contribution
     * POST /api/contributions
     */
    public function store(StoreContributionRequest $request)
    {
        $expectedDate = $request->input('expected_date');
        if ($expectedDate === '') {
            $expectedDate = null;
        }
        $result = $this->contributionService->record(
            memberId: $request->integer('user_id'),
            amount: (float) $request->input('amount'),
            expectedDate: $expectedDate,
            paidDate: $request->input('paid_date'),
            recordedBy: (int) $request->user()->id,
            period: $request->input('period') // ✅ THIS WAS MISSING
        );

        return response()->json([
            'message' => 'Contribution recorded successfully',
            'data' => $result['start']->load('user:id,name,email,phone'),
            'allocations' => $result['allocations'] ?? [],
        ], 201);
    }

    /**
     * Mark missed contribution (creates contribution with status missed + penalty)
     * POST /api/contributions/missed
     */
    public function markMissed(MarkMissedContributionRequest $request)
    {
        $expectedDate = $request->input('expected_date');
        $period = $request->input('period');

        if ($expectedDate) {
            // legacy/manual override
            $contribution = $this->contributionService->markMissed(
                memberId: $request->integer('user_id'),
                expectedDate: $expectedDate,
                recordedBy: $request->user()->id
            );
        } else {
            // modern rule-driven
            $contribution = $this->contributionService->markMissedByPeriod(
                memberId: $request->integer('user_id'),
                period: $period,
                recordedBy: $request->user()->id
            );
        }

        return response()->json([
            'message' => 'Contribution marked as missed',
            'data' => $contribution->load('user:id,name,email,phone'),
        ], 201);
    }

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
        $period = $request->query('period'); // YYYY-MM
        $periodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');

        $rules = SystemRule::firstOrFail();

        // NOTE: adjust this query to your "member" definition
        // If you have is_active column, uncomment accordingly.
        $membersQuery = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role']);

        if (!($request->boolean('include_inactive'))) {
            // If you have an is_active column:
            // $membersQuery->where('is_active', 1);
        }

        // If you only want members (not admin/treasurer), adjust:
        // $membersQuery->where('role', 'member');

        $members = $membersQuery->orderBy('name')->get();

        // ✅ preload opening balances for members (fast lookup)
        $openingByUser = \App\Models\OpeningBalance::query()
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        // existing envelopes for the period (fast lookup)
        $existing = Contribution::query()
            ->where('period_key', $periodKey)
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $openingBalances = OpeningBalance::query()
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->keyBy('user_id');

        $expectedDate = $this->contributionServiceExpectedFromRules($rules, $periodKey);

        $rows = $members->map(function ($m) use ($periodKey, $existing, $expectedDate, $openingBalances, $openingByUser) {
            $env = $existing->get($m->id);

            // commitment for this period (can be null)
            $commitment = $this->commitmentService->activeForPeriod($m->id, $periodKey);

            $ob = $openingBalances->get($m->id);

            // Decide if the member is initialized
            $openingSet = (bool) $ob;

            $opening = $openingByUser->get($m->id);
            $hasOpening = (bool) $opening;

            $canProcess = true;
            $reason = null;

            if (!$openingSet) {
                $canProcess = false;
                $reason = "Opening capital not set.";
            }
            if (!$commitment) {
                $canProcess = false;
                $reason = "No commitment set for this period.";
            }

            if ($env && in_array($env->status, ['paid', 'late'], true)) {
                // You can still show it, but it will be skipped in bulkStore
                $reason = "Already recorded ({$env->status}). Will be skipped.";
            }
            return [
                'user' => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'email' => $m->email,
                    'phone' => $m->phone,
                    'role' => $m->role,
                ],
                'period' => $periodKey,
                // ✅ opening capital info
                'opening_balance_set' => $openingSet,
                'opening_balance' => $ob ? [
                    'amount' => (float) $ob->amount,
                    'as_of_period' => $ob->as_of_period,
                ] : null,

                'commitment_amount' => $commitment ? (float) $commitment->amount : null,
                // ✅ opening balance flags for UI
                'has_opening' => $hasOpening,
                'opening_balance' => $opening ? (float) $opening->amount : null,
                'opening_as_of' => $opening?->as_of_period,

                'expected_date' => $expectedDate, // default for UI
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
            'period' => $periodKey,
            'expected_date_default' => $expectedDate,
            'rows' => $rows,
        ]);
    }

    /**
     * Small helper (controller-local) to compute expected date from rules.
     * Keeps bulkPreview independent.
     */
    private function contributionServiceExpectedFromRules(SystemRule $rules, string $periodKey): string
    {
        $dueDay = (int) ($rules->contribution_due_day ?? 25);

        $first = Carbon::createFromFormat('Y-m-d', $periodKey . '-01')->startOfDay();
        $lastDay = $first->copy()->endOfMonth()->day;

        return $first->copy()->day(min($dueDay, $lastDay))->format('Y-m-d');
    }
    public function bulkStore(BulkStoreContributionsRequest $request)
    {
        $period = $request->input('period'); // YYYY-MM
        $periodKey = Carbon::createFromFormat('Y-m', $period)->format('Y-m');

        $defaultPaidDate = $request->input('paid_date');       // optional
        $defaultExpected = $request->input('expected_date');   // optional

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

        $userIds = collect($items)->pluck('user_id')->filter()->unique()->map(fn($v) => (int)$v)->values();

        $existingByUser = Contribution::query()
            ->where('period_key', $periodKey)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $openingByUser = \App\Models\OpeningBalance::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        foreach ($items as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $amount = (float) ($row['amount'] ?? 0);
            $missed = (bool) ($row['missed'] ?? false);

            $paidDate = $row['paid_date'] ?? $defaultPaidDate;
            $expected = $row['expected_date'] ?? $defaultExpected;

            try {
                if ($userId <= 0) {
                    throw new \InvalidArgumentException("user_id is required.");
                }

                $existing = $existingByUser->get($userId);
                $hadExisting = (bool) $existing;

                // ✅ Opening capital required
                $opening = $openingByUser->get($userId);
                if (!$opening) {
                    $results['success'][] = [
                        'user_id' => $userId,
                        'action' => 'skipped_no_opening_balance',
                        'period_key' => $periodKey,
                        'message' => 'Opening capital not set.',
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }

                // Optional: block periods before opening start
                if (!empty($opening->as_of_period) && $periodKey < $opening->as_of_period) {
                    $results['success'][] = [
                        'user_id' => $userId,
                        'action' => 'skipped_before_opening_period',
                        'period_key' => $periodKey,
                        'opening_as_of' => $opening->as_of_period,
                        'message' => 'Period is before opening capital as-of period.',
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }

                // ✅ Commitment required for start period
                $commitment = $this->commitmentService->activeForPeriod($userId, $periodKey);
                if (!$commitment) {
                    $results['success'][] = [
                        'user_id' => $userId,
                        'action' => 'skipped_no_commitment',
                        'period_key' => $periodKey,
                        'message' => "No commitment set for this period.",
                    ];
                    $results['totals']['ok']++;
                    $results['totals']['skipped']++;
                    continue;
                }
                $monthlyTarget = (float) $commitment->amount;

                // ✅ Case 1: Missed (handle BEFORE fully-covered skip)
                if ($missed || $amount <= 0) {

                    // If already paid/late and fully funded => do NOT mark missed
                    if ($existing && in_array($existing->status, ['paid', 'late'], true) && (float)$existing->amount >= $monthlyTarget) {
                        $results['success'][] = [
                            'user_id' => $userId,
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

                    // If envelope exists but not missed, do not silently "return existing"
                    if ($existing && $existing->status !== 'missed') {
                        throw new \InvalidArgumentException(
                            "Cannot mark missed: existing contribution already exists for {$periodKey} (status {$existing->status}, amount {$existing->amount})."
                        );
                    }

                    $c = $this->contributionService->markMissedByPeriod(
                        memberId: $userId,
                        period: $periodKey,
                        recordedBy: $recordedBy,
                        expectedDate: $expected
                    );

                    $results['success'][] = [
                        'user_id' => $userId,
                        'action' => $existing ? 'missed_exists' : 'missed',
                        'contribution_id' => $c->id,
                        'period_key' => $c->period_key,
                        'status' => $c->status,
                    ];

                    $results['totals']['ok']++;
                    $results['totals']['missed_count']++;

                    $existingByUser->put($userId, $c); // keep cache consistent
                    continue;
                }

                // ✅ Case 2: Paid (NOW we can skip if fully covered)
                if ($existing && (float)$existing->amount >= $monthlyTarget) {
                    $results['success'][] = [
                        'user_id' => $userId,
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
                    throw new \InvalidArgumentException("paid_date is required for paid rows (user_id {$userId}).");
                }

                $res = $this->contributionService->record(
                    memberId: $userId,
                    amount: $amount,
                    expectedDate: $expected,
                    paidDate: $paidDate,
                    recordedBy: $recordedBy,
                    period: $periodKey
                );

                $start = $res['start'] ?? null;

                $results['success'][] = [
                    'user_id' => $userId,
                    'action' => $hadExisting ? 'topped_up_or_allocated' : 'paid',
                    'amount' => $amount,
                    'start_contribution_id' => $start?->id,
                    'period_key' => $periodKey,
                    'allocations' => $res['allocations'] ?? [],
                ];

                $results['totals']['ok']++;
                $results['totals']['amount_sum'] += $amount;

                if ($start) {
                    $existingByUser->put($userId, $start);
                }
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'user_id' => $userId,
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
    
}
