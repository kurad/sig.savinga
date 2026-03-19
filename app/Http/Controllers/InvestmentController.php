<?php

namespace App\Http\Controllers;

use App\Models\Investment;
use App\Services\InvestmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvestmentController extends Controller
{
    public function __construct(
        protected InvestmentService $investmentService
    ) {}

    public function index()
    {
        $investments = Investment::with('recordedBy:id,name')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'data' => $investments
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'total_amount' => 'required|numeric|gt:0',
            'invested_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $investment = $this->investmentService->createInvestment(
                name: $request->name,
                description: $request->description,
                totalAmount: (float) $request->total_amount,
                investedDate: $request->invested_date,
                recordedBy: $request->user()->id
            );

            $investment->load('recordedBy:id,name');

            return response()->json([
                'message' => 'Investment created successfully.',
                'data' => $investment
            ], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function sell(Request $request, Investment $investment)
    {
        $validator = Validator::make($request->all(), [
            'sale_amount' => 'required|numeric|gt:0',
            'sale_date' => 'required|date|after_or_equal:' . $investment->invested_date,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $investment = $this->investmentService->sellInvestment(
                investmentId: $investment->id,
                saleAmount: (float) $request->sale_amount,
                saleDate: $request->sale_date,
                recordedBy: $request->user()->id
            );

            $investment->load('recordedBy:id,name');

            return response()->json([
                'message' => 'Investment sold successfully.',
                'data' => $investment
            ]);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}