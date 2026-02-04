<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SystemRule;
use App\Services\CommitmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CommitmentController extends Controller
{
    public function __construct(
        protected CommitmentService $commitmentService
    ) {}

    /**
     * List commitments (optionally filter by user_id or status).
     * GET /admin/commitments?user_id=1&status=active
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status'  => ['nullable', 'in:active,expired'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 50);

        $query = \App\Models\ContributionCommitment::query()
            ->with(['user:id,name,email,phone'])
            ->orderByDesc('cycle_start_period');

        if (!empty($data['user_id'])) {
            $query->where('user_id', (int) $data['user_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Show active commitment for a user in a given period.
     * GET /admin/commitments/active?user_id=1&period=2024-03
     */
    public function active(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'period'  => ['required', 'date_format:Y-m'],
        ]);

        $commitment = $this->commitmentService->activeForPeriod(
            userId: (int) $data['user_id'],
            periodKey: $data['period']
        );

        return response()->json([
            'data' => $commitment?->load('user:id,name,email,phone'),
        ]);
    }

    /**
     * Create (or update) a commitment for a cycle.
     *
     * Rule: "Only at cycle end" => commitment can ONLY start on a cycle start month.
     *
     * POST /admin/commitments
     * Body:
     * {
     *   "user_id": 3,
     *   "amount": 15000,
     *   "start_period": "2024-01"
     * }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id'       => ['required', 'integer', 'exists:users,id'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'start_period'  => ['required', 'date_format:Y-m'],
        ]);

        $rules = SystemRule::firstOrFail();

        $min = (float) ($rules->contribution_min_amount ?? 0);
        if ((float) $data['amount'] < $min) {
            throw ValidationException::withMessages([
                'amount' => ["Amount cannot be below minimum ({$min})."],
            ]);
        }

        $cycleMonths = (int) ($rules->contribution_cycle_months ?? 12);
        $anchor      = (string) ($rules->cycle_anchor_period ?? $data['start_period']);

        [$cycleStart, $cycleEnd] = $this->commitmentService->cycleWindow(
            period: $data['start_period'],
            anchor: $anchor,
            cycleMonths: $cycleMonths
        );

        // Enforce "cycle only": must start exactly at cycle start.
        if ($data['start_period'] !== $cycleStart) {
            throw ValidationException::withMessages([
                'start_period' => ["Commitment can only start at cycle start ({$cycleStart})."],
            ]);
        }

        $commitment = $this->commitmentService->setForCycle(
            userId: (int) $data['user_id'],
            amount: (float) $data['amount'],
            cycleStart: $cycleStart,
            cycleEnd: $cycleEnd,
            cycleMonths: $cycleMonths,
            createdBy: (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Commitment saved successfully',
            'data' => $commitment->load('user:id,name,email,phone'),
        ], 201);
    }

    /**
     * Optional: Expire a commitment manually (admin action).
     * POST /admin/commitments/{id}/expire
     */
    public function expire(\App\Models\ContributionCommitment $commitment)
    {
        if ($commitment->status === 'expired') {
            return response()->json([
                'message' => 'Commitment already expired.',
                'data' => $commitment->load('user:id,name,email,phone'),
            ]);
        }

        $commitment->update(['status' => 'expired']);

        return response()->json([
            'message' => 'Commitment expired.',
            'data' => $commitment->refresh()->load('user:id,name,email,phone'),
        ]);
    }
}
