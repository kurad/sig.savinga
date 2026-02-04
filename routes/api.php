<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\LoanController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\DueDateController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\OtpAuthController;
use App\Http\Controllers\PenaltyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\CommitmentController;
use App\Http\Controllers\ProfitCycleController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\MeContributionController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\FinancialYearRuleController;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// OTP
Route::post('/auth/otp/request', [OtpAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/otp/verify', [OtpAuthController::class, 'verify'])->middleware('throttle:15,1');

/**
 * =========================================================
 * AUTHENTICATED ROUTES
 * =========================================================
 */
Route::middleware('auth:sanctum')->group(function () {

    /**
     * ---------------------------------------------------------
     * SELF (any authenticated user)
     * These endpoints must enforce "self" inside controller/service
     * ---------------------------------------------------------
     */
    Route::get('/members/{user}/statement', [StatementController::class, 'show']);
    Route::get('/members/{user}/loans/summary', [LoanController::class, 'memberSummary']);
    Route::get('/members/{user}/loans', [LoanController::class, 'memberLoans']);
    Route::get('/members/{user}/contributions/summary', [ContributionController::class, 'memberSummary']);
    Route::get('/members/{user}/penalties/summary', [PenaltyController::class, 'memberSummary']);
    Route::get('/me/next-due', [DueDateController::class, 'myNextDue']);
    Route::get('/me/contributions/allocation', [MeContributionController::class, 'allocation']);
    Route::get('/members/{user}/penalties', [PenaltyController::class, 'memberPenalties']);
    Route::get('/me/opening-balance', [OpeningBalanceController::class, 'myOpeningBalance']);


    /**
     * ---------------------------------------------------------
     * ADMIN/TREASURER (group management)
     * ---------------------------------------------------------
     */
    Route::middleware('role:admin,treasurer')->group(function () {

        // Dashboard (group overview)
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Members management
        Route::get('/members', [MemberController::class, 'index']);
        Route::post('/members', [MemberController::class, 'store']);
        Route::get('/members/{user}', [MemberController::class, 'show']);
        Route::patch('/members/{user}', [MemberController::class, 'update']);
        Route::patch('/members/{user}/toggle-active', [MemberController::class, 'toggleStatus']);

        // Contributions (group-level)
        Route::get('/contributions', [ContributionController::class, 'index']);
        Route::post('/contributions', [ContributionController::class, 'store']);
        Route::post('/contributions/missed', [ContributionController::class, 'markMissed']);

        // Loans (group-level)
        Route::get('/loans', [LoanController::class, 'index']);
        Route::post('/loans', [LoanController::class, 'disburse']);

        Route::post('/loans/{loan}/repay', [LoanController::class, 'repayWithAutoSplit']);
        Route::get('/loans/{loan}/repay/preview', [LoanController::class, 'repayPreview']);

        Route::get('/loans/{loan}/top-up/preview', [LoanController::class, 'topUpPreview']);
        Route::post('/loans/{loan}/top-up', [LoanController::class, 'topUp']);

        Route::match(['get', 'post'], '/loans/disburse/preview', [LoanController::class, 'disbursePreview']);
        Route::match(['get', 'post'], '/loans/eligibility', [LoanController::class, 'eligibility']);



        // Penalties (group-level)
        Route::get('/penalties', [PenaltyController::class, 'index']);
        Route::post('/penalties/manual', [PenaltyController::class, 'storeManual']);
        Route::patch('/penalties/{penalty}/pay', [PenaltyController::class, 'pay']);
        Route::patch('/penalties/{penalty}/waive', [PenaltyController::class, 'waive']);

        // Profit (group-level)
        Route::get('/profit-cycles', [ProfitCycleController::class, 'index']);
        Route::get('/profit-cycles/{cycle}', [ProfitCycleController::class, 'show']);
        Route::post('/profit-cycles/open', [ProfitCycleController::class, 'open']);
        Route::post('/profit-cycles/{cycle}/close', [ProfitCycleController::class, 'closeAndDistribute']);
        Route::patch('/profit-distributions/{distribution}/credit', [ProfitCycleController::class, 'creditDistribution']);
        Route::patch('/profit-distributions/{distribution}/pay', [ProfitCycleController::class, 'payDistribution']);
        Route::patch('/profit-cycles/{cycle}/distributions/credit-all', [ProfitCycleController::class, 'creditAll']);
        Route::patch('/profit-cycles/{cycle}/distributions/pay-all', [ProfitCycleController::class, 'payAll']);
        Route::get('/loans/insights', [LoanController::class, 'insights']);

        // Expenses (group-level)
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);

        // Incomes (group-level)
        Route::get('/incomes', [IncomeController::class, 'index']);
        Route::post('/incomes', [IncomeController::class, 'store']);

        // Commitments (admin/treasurer)
        Route::get('/commitments', [CommitmentController::class, 'index']);
        Route::get('/commitments/active', [CommitmentController::class, 'active']);
        Route::post('/commitments', [CommitmentController::class, 'store']);
        Route::patch('/commitments/{commitment}/expire', [CommitmentController::class, 'expire']);

        // Bulk contributions
        Route::get('/contributions/bulk/preview', [ContributionController::class, 'bulkPreview']);
        Route::post('/contributions/bulk', [ContributionController::class, 'bulkStore']);

        Route::post('/opening-balances', [OpeningBalanceController::class, 'store']);
        Route::get('/opening-balances/user/{userId}', [OpeningBalanceController::class, 'showByUser']);


        // Financial year rules (admin settings)
        Route::get('/financial-year-rules', [FinancialYearRuleController::class, 'index']);
        Route::get('/financial-year-rules/active', [FinancialYearRuleController::class, 'active']);

        Route::post('/financial-year-rules', [FinancialYearRuleController::class, 'store']);
        Route::put('/financial-year-rules/{financialYearRule}', [FinancialYearRuleController::class, 'update']);
        Route::delete('/financial-year-rules/{financialYearRule}', [FinancialYearRuleController::class, 'destroy']);

        Route::post('/financial-year-rules/{financialYearRule}/activate', [FinancialYearRuleController::class, 'activate']);
        Route::get('/members/{user}/next-due', [DueDateController::class, 'memberNextDue']);

        Route::get('/statement', [StatementController::class, 'index']);
    });
});
