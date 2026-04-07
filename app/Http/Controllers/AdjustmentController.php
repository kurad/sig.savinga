<?php

namespace App\Http\Controllers;

use App\Models\Contribution;
use App\Models\Loan;
use App\Models\OpeningBalance;
use App\Services\AdjustmentService;
use App\Services\ContributionService;
use App\Services\LoanService;
use Illuminate\Http\Request;

class AdjustmentController extends Controller
{
    public function __construct(
        private AdjustmentService $service,
        private ContributionService $contributionService,
        private LoanService $loanService
    ) {}

    public function storeOpeningBalance(Request $request, OpeningBalance $openingBalance)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $adminId = (int) auth()->id();

        $row = $this->service->create($openingBalance, $validated, $adminId);

        return response()->json([
            'message' => 'Opening balance adjustment posted successfully.',
            'data' => $row,
        ], 201);
    }

    public function storeContribution(Request $request, Contribution $contribution)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $row = $this->contributionService->adjustContribution(
            contributionId: (int) $contribution->id,
            amount: (float) $validated['amount'],
            reason: (string) $validated['reason'],
            recordedBy: (int) auth()->id()
        );

        return response()->json([
            'message' => 'Contribution adjustment posted successfully.',
            'data' => $row,
        ], 201);
    }

    public function storeLoan(Request $request, Loan $loan)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $row = $this->loanService->adjustLoan(
            loanId: (int) $loan->id,
            amount: (float) $validated['amount'],
            reason: (string) $validated['reason'],
            recordedBy: (int) auth()->id()
        );

        return response()->json([
            'message' => 'Loan adjustment posted successfully.',
            'data' => $row,
        ], 201);
    }
}
