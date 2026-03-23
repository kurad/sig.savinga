<?php

namespace App\Http\Controllers;

use App\Http\Requests\Incomes\StoreIncomeRequest;
use App\Services\IncomeReportService;
use App\Services\IncomeService;
use Illuminate\Http\Request;

class IncomeController extends Controller
{
    public function __construct(
        protected IncomeService $incomeService,
        protected IncomeReportService $reportService
    ) {}

    public function index(Request $request)
    {
        $data = $this->reportService->list(
            filters: $request->only(['from', 'to', 'category']),
            perPage: 15
        );

        return response()->json($data);
    }

    public function store(StoreIncomeRequest $request)
    {
        $income = $this->incomeService->record(
            amount: (float) $request->input('amount'),
            incomeDate: $request->input('income_date'),
            recordedBy: $request->user()->id,
            category: $request->input('category'),
            description: $request->input('description'),
            userId: $request->input('user_id'),
            beneficiaryId: $request->input('beneficiary_id')
        );

        return response()->json([
            'message' => 'Income recorded successfully',
            'data' => $income,
        ], 201);
    }
}