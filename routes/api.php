<?php

use App\Http\Controllers\AdjustmentController;
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
use App\Http\Controllers\LoanMigrationController;
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

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/demo-requests', [DemoRequestController::class, 'store']);

/*
|--------------------------------------------------------------------------
| Login / Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth/login')->group(function () {
    Route::post('/totp', [LoginController::class, 'loginWithTotp']);

    Route::post('/email/request', [LoginController::class, 'requestEmailCode']);
    Route::post('/email/verify', [LoginController::class, 'verifyEmailCode']);
});

// OTP / SMS Authentication
Route::post('/auth/otp-sms/request', [OtpAuthController::class, 'login'])
    ->middleware('throttle:10,1');

Route::post('/auth/otp/request-otp', [OtpAuthController::class, 'requestOtp'])
    ->middleware('throttle:10,1');

Route::post('/auth/otp-sms/verify', [OtpAuthController::class, 'verify'])
    ->middleware('throttle:15,1');

Route::post('/auth/otp/verify-otp', [OtpAuthController::class, 'verifyOtp'])
    ->middleware('throttle:15,1');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    */

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    /*
    |--------------------------------------------------------------------------
    | Self / Member-facing Routes
    |--------------------------------------------------------------------------
    | These endpoints should still enforce ownership/self access inside
    | the controller or service layer.
    |--------------------------------------------------------------------------
    */

    Route::get('/me/next-due', [DueDateController::class, 'myNextDue']);
    Route::get('/me/contributions/allocation', [MeContributionController::class, 'allocation']);
    Route::get('/me/opening-balance', [OpeningBalanceController::class, 'myOpeningBalance']);

    Route::get('/members/{user}/statement', [StatementController::class, 'show']);

    Route::get('/members/{user}/loans', [LoanController::class, 'memberLoans']);
    Route::get('/members/{user}/loans/summary', [LoanController::class, 'memberSummary']);

    Route::get('/members/{user}/contributions/summary', [ContributionController::class, 'memberSummary']);

    Route::get('/members/{user}/penalties', [PenaltyController::class, 'memberPenalties']);
    Route::get('/members/{user}/penalties/summary', [PenaltyController::class, 'memberSummary']);

    /*
    |--------------------------------------------------------------------------
    | Admin / Treasurer Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:admin,treasurer')->group(function () {
        /*
        |--------------------------------------------------------------------------
        | Dashboard / Demo Requests
        |--------------------------------------------------------------------------
        */

        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/demo-requests', [DemoRequestController::class, 'index']);

        /*
        |--------------------------------------------------------------------------
        | Members
        |--------------------------------------------------------------------------
        */

        Route::get('/members', [MemberController::class, 'index']);
        Route::post('/members', [MemberController::class, 'store']);
        Route::post('/members/import', [MemberController::class, 'importFromExcel']);

        Route::get('/participants/dropdown', [MemberController::class, 'participantsDropdown']);

        Route::get('/members/{user}', [MemberController::class, 'show']);
        Route::patch('/members/{user}', [MemberController::class, 'update']);
        Route::patch('/members/{user}/toggle-active', [MemberController::class, 'toggleStatus']);
        Route::get('/members/{user}/next-due', [DueDateController::class, 'memberNextDue']);

        Route::get('/reports/group-financial/export', [MemberController::class, 'exportGroupFinancialReport']);

        /*
        |--------------------------------------------------------------------------
        | Beneficiaries
        |--------------------------------------------------------------------------
        */

        Route::get('/beneficiaries', [BeneficiaryController::class, 'index']);
        Route::post('/beneficiaries', [BeneficiaryController::class, 'store']);

        Route::get('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show']);
        Route::put('/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'update']);
        Route::patch('/beneficiaries/{beneficiary}/set-active', [BeneficiaryController::class, 'setActive']);

        /*
        |--------------------------------------------------------------------------
        | Opening Balances
        |--------------------------------------------------------------------------
        */

        Route::post('/opening-balances', [OpeningBalanceController::class, 'store']);
        Route::get('/opening-balances/user/{userId}', [OpeningBalanceController::class, 'showByUser']);
        Route::get('/opening-balances/beneficiary/{beneficiaryId}', [OpeningBalanceController::class, 'showByBeneficiary']);
        Route::put('/opening-balances/{openingBalance}', [OpeningBalanceController::class, 'update']);

        /*
        |--------------------------------------------------------------------------
        | Commitments
        |--------------------------------------------------------------------------
        */

        Route::get('/commitments', [CommitmentController::class, 'index']);
        Route::post('/commitments', [CommitmentController::class, 'store']);

        Route::get('/commitments/active', [CommitmentController::class, 'active']);
        Route::get('/commitments/by-participant', [CommitmentController::class, 'showByParticipant']);

        Route::put('/commitments/{commitment}', [CommitmentController::class, 'update']);
        Route::patch('/commitments/{commitment}/expire', [CommitmentController::class, 'expire']);

        Route::post('/commitments/{id}/change', [CommitmentController::class, 'changeCommitment']);

        /*
        |--------------------------------------------------------------------------
        | Contributions
        |--------------------------------------------------------------------------
        */

        Route::get('/contributions', [ContributionController::class, 'index']);
        Route::post('/contributions', [ContributionController::class, 'store']);

        Route::post('/contributions/preview', [ContributionController::class, 'preview']);
        Route::post('/contributions/missed', [ContributionController::class, 'markMissed']);

        Route::post('/contributions/undo', [ContributionController::class, 'undo']);
        Route::post('/contributions/undo-last', [ContributionController::class, 'undoLast']);
        Route::post('/contributions/batches/{batchId}/undo', [ContributionController::class, 'undoBatch']);

        // Bulk contributions
        Route::get('/contributions/bulk/preview', [ContributionController::class, 'bulkPreview']);
        Route::post('/contributions/bulk', [ContributionController::class, 'bulkStore']);

        // Payroll contributions
        Route::post('/contributions/payroll/preview', [ContributionPayrollController::class, 'preview']);
        Route::post('/contributions/payroll/commit', [ContributionPayrollController::class, 'commit']);

        /*
        |--------------------------------------------------------------------------
        | Loans
        |--------------------------------------------------------------------------
        | Keep fixed paths like /loans/insights before any future /loans/{loan}
        | route to avoid route model binding conflicts.
        |--------------------------------------------------------------------------
        */

        Route::get('/loans/insights', [LoanController::class, 'insights']);

        Route::get('/loans', [LoanController::class, 'index']);
        Route::post('/loans', [LoanController::class, 'disburse']);

        Route::match(['get', 'post'], '/loans/disburse/preview', [LoanController::class, 'disbursePreview']);
        Route::match(['get', 'post'], '/loans/eligibility', [LoanController::class, 'eligibility']);

        Route::get('/loans/{loan}/repay/preview', [LoanController::class, 'repayPreview']);
        Route::post('/loans/{loan}/repay', [LoanController::class, 'repayWithAutoSplit']);

        Route::get('/loans/{loan}/top-up/preview', [LoanController::class, 'topUpPreview']);
        Route::post('/loans/{loan}/top-up', [LoanController::class, 'topUp']);

        /*
        |--------------------------------------------------------------------------
        | Loan Migration
        |--------------------------------------------------------------------------
        | Main clean routes:
        | - GET  /loans/migration/template
        | - POST /loans/migration/import
        | - POST /loans/migration/from-member
        | - POST /loans/{loan}/migration
        |--------------------------------------------------------------------------
        */

        Route::prefix('loans/migration')->group(function () {
            Route::get('/template', [LoanMigrationController::class, 'template']);
            Route::post('/import', [LoanMigrationController::class, 'import']);
            Route::post('/from-member', [LoanMigrationController::class, 'storeFromMember']);
        });

        Route::prefix('loans/{loan}/migration')->group(function () {
            Route::post('/', [LoanMigrationController::class, 'store']);
            Route::get('/outstanding', [LoanMigrationController::class, 'outstanding']);
            Route::get('/summary', [LoanMigrationController::class, 'summary']);
        });

        /*
        |--------------------------------------------------------------------------
        | Backward-compatible Loan Migration Aliases
        |--------------------------------------------------------------------------
        | Keep these temporarily only if your frontend or old tools already use them.
        | You can remove them after updating the frontend.
        |--------------------------------------------------------------------------
        */

        Route::post('/loan-migrations', [LoanMigrationController::class, 'storeFromMember']);
        Route::post('/loans/migrate-from-member', [LoanMigrationController::class, 'storeFromMember']);
        Route::post('/loans/{loan}/migrate', [LoanMigrationController::class, 'store']);

        /*
        |--------------------------------------------------------------------------
        | Penalties
        |--------------------------------------------------------------------------
        */

        Route::get('/penalties', [PenaltyController::class, 'index']);
        Route::post('/penalties/manual', [PenaltyController::class, 'storeManual']);

        Route::patch('/penalties/{penalty}/pay', [PenaltyController::class, 'pay']);
        Route::patch('/penalties/{penalty}/waive', [PenaltyController::class, 'waive']);

        /*
        |--------------------------------------------------------------------------
        | Profit Cycles
        |--------------------------------------------------------------------------
        */

        Route::get('/profit-cycles', [ProfitCycleController::class, 'index']);
        Route::get('/profit-cycles/{cycle}', [ProfitCycleController::class, 'show']);

        Route::post('/profit-cycles/open', [ProfitCycleController::class, 'open']);
        Route::post('/profit-cycles/{cycle}/close', [ProfitCycleController::class, 'closeAndDistribute']);

        Route::patch('/profit-distributions/{distribution}/credit', [ProfitCycleController::class, 'creditDistribution']);
        Route::patch('/profit-distributions/{distribution}/pay', [ProfitCycleController::class, 'payDistribution']);

        Route::patch('/profit-cycles/{cycle}/distributions/credit-all', [ProfitCycleController::class, 'creditAll']);
        Route::patch('/profit-cycles/{cycle}/distributions/pay-all', [ProfitCycleController::class, 'payAll']);

        /*
        |--------------------------------------------------------------------------
        | Expenses / Incomes / Investments
        |--------------------------------------------------------------------------
        */

        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);

        Route::get('/incomes', [IncomeController::class, 'index']);
        Route::post('/incomes', [IncomeController::class, 'store']);

        Route::get('/investments', [InvestmentController::class, 'index']);
        Route::post('/investments', [InvestmentController::class, 'store']);
        Route::post('/investments/{investment}/sell', [InvestmentController::class, 'sell']);

        /*
        |--------------------------------------------------------------------------
        | Financial Year Rules
        |--------------------------------------------------------------------------
        */

        Route::get('/financial-year-rules', [FinancialYearRuleController::class, 'index']);
        Route::post('/financial-year-rules', [FinancialYearRuleController::class, 'store']);

        Route::get('/financial-year-rules/active', [FinancialYearRuleController::class, 'active']);

        Route::put('/financial-year-rules/{financialYearRule}', [FinancialYearRuleController::class, 'update']);
        Route::delete('/financial-year-rules/{financialYearRule}', [FinancialYearRuleController::class, 'destroy']);

        Route::post('/financial-year-rules/{financialYearRule}/activate', [FinancialYearRuleController::class, 'activate']);

        /*
        |--------------------------------------------------------------------------
        | Statements / Member Financial Years / System Rules
        |--------------------------------------------------------------------------
        */

        Route::get('/statement', [StatementController::class, 'index']);

        Route::get('/member-financial-years', [MemberFinancialYearController::class, 'show']);
        Route::post('/member-financial-years/upsert', [MemberFinancialYearController::class, 'upsert']);

        Route::get('/system-rules', [SystemRuleController::class, 'show']);
        Route::put('/system-rules', [SystemRuleController::class, 'update']);

        /*
        |--------------------------------------------------------------------------
        | Adjustments
        |--------------------------------------------------------------------------
        */

        Route::post('/opening-balances/{openingBalance}/adjustments', [AdjustmentController::class, 'storeOpeningBalance']);
        Route::post('/contributions/{contribution}/adjustments', [AdjustmentController::class, 'storeContribution']);
        Route::post('/loans/{loan}/adjustments', [AdjustmentController::class, 'storeLoan']);
    });
});