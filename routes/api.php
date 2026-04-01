<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BeneficiaryController;
use App\Http\Controllers\CommitmentController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\ContributionPayrollController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\DueDateController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialYearRuleController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MeContributionController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberFinancialYearController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\OtpAuthController;
use App\Http\Controllers\PenaltyController;
use App\Http\Controllers\ProfitCycleController;
use App\Http\Controllers\StatementController;
use App\Http\Controllers\SystemRuleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('auth/login')->group(function () {
    Route::post('/totp', [LoginController::class, 'loginWithTotp']);

    Route::post('/email/request', [LoginController::class, 'requestEmailCode']);
    Route::post('/email/verify',  [LoginController::class, 'verifyEmailCode']);
});
Route::post('/demo-requests', [DemoRequestController::class, 'store']);

// OTP
Route::post('/auth/otp-sms/request', [OtpAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/otp/request-otp', [OtpAuthController::class, 'requestOtp'])->middleware('throttle:10,1');
Route::post('/auth/otp-sms/verify', [OtpAuthController::class, 'verify'])->middleware('throttle:15,1');
Route::post('/auth/otp/verify-otp', [OtpAuthController::class, 'verifyOtp'])->middleware('throttle:15,1');

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
        Route::post('/members/import', [MemberController::class, 'importFromExcel']);

        Route::post('/beneficiaries', [BeneficiaryController::class, 'store']);
        Route::patch('beneficiaries/{beneficiary}/set-active', [BeneficiaryController::class, 'setActive']);

        Route::get('/opening-balances/beneficiary/{beneficiaryId}', [OpeningBalanceController::class, 'showByBeneficiary']);
        Route::put('/opening-balances/{openingBalance}', [OpeningBalanceController::class, 'update']);

        Route::get('/commitments/by-participant', [CommitmentController::class, 'showByParticipant']);
        Route::put('/commitments/{commitment}', [CommitmentController::class, 'update']);

        // Contributions (group-level)
        Route::get('/contributions', [ContributionController::class, 'index']);
        Route::post('/contributions', [ContributionController::class, 'store']);
        Route::post('/contributions/missed', [ContributionController::class, 'markMissed']);
        Route::post('/contributions/undo', [ContributionController::class, 'undo']);
        Route::post('/contributions/preview', [ContributionController::class, 'preview']);

        Route::post('/contributions/batches/{batchId}/undo', [ContributionController::class, 'undoBatch']);
        Route::post('/contributions/undo-last', [ContributionController::class, 'undoLast']);
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

        // Investments (group-level)
        Route::get('/investments', [InvestmentController::class, 'index']);
        Route::post('/investments', [InvestmentController::class, 'store']);
        Route::post('/investments/{investment}/sell', [InvestmentController::class, 'sell']);

        // Commitments (admin/treasurer)
        Route::get('/commitments', [CommitmentController::class, 'index']);
        Route::get('/commitments/active', [CommitmentController::class, 'active']);
        Route::post('/commitments', [CommitmentController::class, 'store']);
        Route::patch('/commitments/{commitment}/expire', [CommitmentController::class, 'expire']);

        // Bulk contributions
        Route::get('/contributions/bulk/preview', [ContributionController::class, 'bulkPreview']);
        Route::post('/contributions/bulk', [ContributionController::class, 'bulkStore']);


        Route::post('/contributions/payroll/preview', [ContributionPayrollController::class, 'preview']);
        Route::post('/contributions/payroll/commit',  [ContributionPayrollController::class, 'commit']);

        Route::post('/opening-balances', [OpeningBalanceController::class, 'store']);
        Route::get('/opening-balances/user/{userId}', [OpeningBalanceController::class, 'showByUser']);
        Route::get('/opening-balances/beneficiary/{beneficiaryId}', [OpeningBalanceController::class, 'showByBeneficiary']);


        // Financial year rules (admin settings)
        Route::get('/financial-year-rules', [FinancialYearRuleController::class, 'index']);
        Route::get('/financial-year-rules/active', [FinancialYearRuleController::class, 'active']);

        Route::post('/financial-year-rules', [FinancialYearRuleController::class, 'store']);
        Route::put('/financial-year-rules/{financialYearRule}', [FinancialYearRuleController::class, 'update']);
        Route::delete('/financial-year-rules/{financialYearRule}', [FinancialYearRuleController::class, 'destroy']);

        Route::post('/financial-year-rules/{financialYearRule}/activate', [FinancialYearRuleController::class, 'activate']);
        Route::get('/members/{user}/next-due', [DueDateController::class, 'memberNextDue']);

        Route::get('/statement', [StatementController::class, 'index']);

        Route::post('/member-financial-years/upsert', [MemberFinancialYearController::class, 'upsert']);
        Route::get('/member-financial-years', [MemberFinancialYearController::class, 'show']);

        Route::get('/system-rules', [SystemRuleController::class, 'show']);
        Route::put('/system-rules', [SystemRuleController::class, 'update']);
    });
});
