<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profits\CloseProfitCycleRequest;
use App\Http\Requests\Profits\OpenProfitCycleRequest;
use App\Http\Requests\Profits\ResolveProfitDistributionRequest;
use App\Models\ProfitCycle;
use App\Models\ProfitDistribution;
use App\Services\ProfitCycleService;
use App\Services\ProfitReportService;
use Illuminate\Http\Request;

class ProfitCycleController extends Controller
{
    public function __construct(
        protected ProfitCycleService $profitService,
        protected ProfitReportService $reportService
    ) {}

    public function index()
    {
        return response()->json($this->reportService->list(15));
    }

    public function show(ProfitCycle $cycle)
    {
        return response()->json($this->reportService->show($cycle->id));
    }

    public function open(OpenProfitCycleRequest $request)
    {
        $cycle = $this->profitService->open(
            startDate: $request->input('start_date'),
            endDate: $request->input('end_date'),
            openedBy: $request->user()->id
        );

        return response()->json([
            'message' => 'Profit cycle opened',
            'data' => $cycle,
        ], 201);
    }

    public function closeAndDistribute(CloseProfitCycleRequest $request, ProfitCycle $cycle)
    {
        try {
            $updated = $this->profitService->closeAndDistribute(
                cycleId: $cycle->id,
                extraOtherIncome: (float) ($request->input('extra_other_income') ?? 0),
                extraExpenses: (float) ($request->input('extra_expenses') ?? 0),
                distributionType: $request->input('distribution_type') ?? 'credit',
                closedBy: $request->user()->id
            );

            return response()->json([
                'message' => 'Profit cycle closed and distributions generated',
                'data' => $updated,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function creditDistribution(ResolveProfitDistributionRequest $request, ProfitDistribution $distribution)
    {
        try {
            $updated = $this->profitService->creditDistribution(
                distributionId: $distribution->id,
                resolvedBy: $request->user()->id,
                date: $request->input('date')
            );

            return response()->json([
                'message' => 'Distribution credited to savings',
                'data' => $updated,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function payDistribution(ResolveProfitDistributionRequest $request, ProfitDistribution $distribution)
    {
        try {
            $updated = $this->profitService->payDistribution(
                distributionId: $distribution->id,
                resolvedBy: $request->user()->id,
                date: $request->input('date')
            );

            return response()->json([
                'message' => 'Distribution paid as cash',
                'data' => $updated,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
    public function creditAll(ResolveProfitDistributionRequest $request, ProfitCycle $cycle)
    {
        try {
            $result = $this->profitService->creditAllDistributions(
                cycleId: $cycle->id,
                resolvedBy: $request->user()->id,
                date: $request->input('date')
            );

            return response()->json([
                'message' => 'All pending distributions credited',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function payAll(ResolveProfitDistributionRequest $request, ProfitCycle $cycle)
    {
        try {
            $result = $this->profitService->payAllDistributions(
                cycleId: $cycle->id,
                resolvedBy: $request->user()->id,
                date: $request->input('date')
            );

            return response()->json([
                'message' => 'All pending distributions marked as paid',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
