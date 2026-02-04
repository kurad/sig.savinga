<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DueDateService;
use App\Services\CommitmentService;
use Illuminate\Http\Request;

class DueDateController extends Controller
{
    public function __construct(
        protected DueDateService $dueDateService,
        protected CommitmentService $commitmentService
    ) {}

    /**
     * GET /me/next-due
     * Returns next due date for the logged-in member.
     */
    public function myNextDue(Request $request)
    {
        $me = $request->user();

        // Get active commitment for current period (or fallback to latest active)
        $periodKey = now('Africa/Kigali')->format('Y-m');
        $commitment = $this->commitmentService->activeForPeriod($me->id, $periodKey);

        if (!$commitment) {
            return response()->json([
                'message' => 'No active commitment found for your account.',
                'data' => null,
            ], 404);
        }

        $data = $this->dueDateService->computeNextDueForMember(
            memberId: (int) $me->id,
            commitmentAmount: (float) $commitment->amount
        );
        // ✅ commitment for the DUE period (not necessarily "now")
        $dueCommitment = $this->commitmentService->activeForPeriod(
            (int) $me->id,
            (string) $data['next_due_period']
        );

        return response()->json([
            'data' => [
                ...$data,
                'commitment' => [
                    'period_key' => $data['next_due_period'],
                    'amount' => $dueCommitment ? (float) $dueCommitment->amount : null,
                    'status' => $dueCommitment?->status,
                ],
            ]
        ]);
    }

    /**
     * GET /members/{user}/next-due (admin/treasurer view)
     */
    public function memberNextDue(Request $request, User $user)
    {
        // Authorization: only admin/treasurer
        if (!in_array($request->user()->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $periodKey = now('Africa/Kigali')->format('Y-m');
        $commitment = $this->commitmentService->activeForPeriod($user->id, $periodKey);

        if (!$commitment) {
            return response()->json([
                'message' => 'No active commitment found for this member.',
                'data' => null,
            ], 404);
        }

        $data = $this->dueDateService->computeNextDueForMember(
            memberId: (int) $user->id,
            commitmentAmount: (float) $commitment->amount
        );
        $dueCommitment = $this->commitmentService->activeForPeriod(
            (int) $user->id,
            (string) $data['next_due_period']
        );

        return response()->json([
            'data' => [
                ...$data,
                'commitment' => [
                    'period_key' => $data['next_due_period'],
                    'amount' => $dueCommitment ? (float) $dueCommitment->amount : null,
                    'status' => $dueCommitment?->status,
                ],
            ],
        ]);
    }
}
