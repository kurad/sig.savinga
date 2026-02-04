<?php

namespace App\Http\Controllers;

use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Services\ExpenseReportService;
use App\Services\ExpenseService;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseService $expenseService,
        protected ExpenseReportService $reportService
    ) {}

    public function index(Request $request)
    {
        $data = $this->reportService->list(
            filters: $request->only(['from', 'to', 'category']),
            perPage: 15
        );

        return response()->json($data);
    }

    public function store(StoreExpenseRequest $request)
    {
        $expense = $this->expenseService->record(
            amount: (float) $request->input('amount'),
            expenseDate: $request->input('expense_date'),
            recordedBy: $request->user()->id,
            category: $request->input('category'),
            description: $request->input('description')
        );

        return response()->json([
            'message' => 'Expense recorded successfully',
            'data' => $expense,
        ], 201);
    }
}
