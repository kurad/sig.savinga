<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ContributionAllocationService;
use App\Services\CommitmentService;

class MeContributionController extends Controller
{
    public function __construct(
        protected ContributionAllocationService $allocationService,
        protected CommitmentService $commitmentService
    ) {}

    /**
     * GET /me/contributions/allocation?from=YYYY-MM&to=YYYY-MM
     */
    public function allocation(Request $request)
    {
        $me = $request->user();

        $from = (string) $request->query('from', now('Africa/Kigali')->format('Y-m'));
        $to   = (string) $request->query('to', now('Africa/Kigali')->format('Y-m'));

        if (!preg_match('/^\d{4}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}$/', $to)) {
            return response()->json([
                'message' => 'Invalid from/to. Use YYYY-MM.',
                'data' => null,
            ], 422);
        }

        $periodKey = now('Africa/Kigali')->format('Y-m');

        $commitment = $this->commitmentService->activeForPeriod(
            userId: (int) $me->id,
            beneficiaryId: null,
            periodKey: $periodKey
        );

        if (!$commitment) {
            return response()->json([
                'message' => 'No active commitment found for your account.',
                'data' => null,
            ], 404);
        }

        $commitmentAmount = (float) ($commitment->amount ?? 0);

        $data = $this->allocationService->buildAllocation(
            memberId: (int) $me->id,
            commitmentAmount: $commitmentAmount,
            fromPeriod: $from,
            toPeriod: $to
        );

        return response()->json([
            'message' => 'ok',
            'data' => $data,
        ]);
    }
}