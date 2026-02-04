<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\LoanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function __construct(
        protected LoanService $loanService
    ) {}

    /**
     * GET /loans
     * Admin list loans (optionally filter by member + status)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userId  = $request->filled('user_id') ? (int) $request->query('user_id') : null;
        $status  = $request->filled('status') ? (string) $request->query('status') : null;
        $perPage = (int) ($request->query('per_page', 15));
        $perPage = max(1, min(100, $perPage));

        $loans = $this->loanService->listLoans($userId, $status, $perPage);

        return response()->json($loans);
    }

    /**
     * GET /loans/eligibility?user_id=1&principal=50000
     * Returns preview used by UI before disbursement
     */
    public function eligibility(Request $request)
    {
        $viewer = $request->user();

        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id'   => ['required', 'integer', 'exists:users,id'],
            'principal' => ['required', 'numeric', 'min:1'],

            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_basis' => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['nullable', 'integer', 'min:1'],

            'repayment_mode' => ['nullable', 'in:once,installment'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $e = $this->loanService->eligibilityPreview(
                memberId: (int)$data['user_id'],
                principal: (float)$data['principal'],
                viewer: $viewer,
                interestRate: (float)$data['interest_rate'],
                interestBasis: (string)$data['interest_basis'],
                interestTermMonths: ($data['interest_basis'] === 'per_term')
                    ? (int)($data['interest_term_months'] ?? 0)
                    : null,
                repaymentMode: $data['repayment_mode'] ?? 'once',
                durationMonths: (($data['repayment_mode'] ?? 'once') === 'installment')
                    ? (int)($data['duration_months'] ?? 1)
                    : 1
            );

            $allowed = !(bool)($e['blocked'] ?? false);
            $reason = $allowed ? null : implode(' ', (array)($e['blocked_reasons'] ?? ['Not eligible.']));

            return response()->json([
                'allowed' => $allowed,
                'reason'  => $reason,
                'data'    => [
                    'eligibility' => [
                        'savings_base'        => (float)($e['savings_base'] ?? 0),
                        'max_allowed'         => (float)($e['max_allowed'] ?? 0),
                        'requested_principal' => (float)($e['requested_principal'] ?? 0),
                        'shortfall'           => (float)($e['shortfall'] ?? 0),
                        'requires_guarantor'  => (bool)($e['requires_guarantor'] ?? false),
                        'months_contributed'  => (int)($e['months_contributed'] ?? 0),
                        'has_active_loan'     => (bool)($e['has_active_loan'] ?? false),
                        'blocked'             => (bool)($e['blocked'] ?? false),
                        'blocked_reasons'     => (array)($e['blocked_reasons'] ?? []),
                    ],

                    // ✅ NEW: interest + totals (exact keys depend on your service)
                    'pricing' => $e['pricing'] ?? null,
                ],
            ]);
        } catch (\Exception $ex) {
            if ($ex->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            return response()->json(['message' => $ex->getMessage()], 422);
        }
    }


    public function disbursePreview(Request $request)
    {
        return $this->eligibility($request);
    }

    /**
     * POST /loans
     * Disburse loan (recommended REST endpoint)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'principal' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date'],

            'interest_rate'        => ['required', 'numeric', 'min:0.01'],
            'interest_basis'       => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['nullable', 'integer', 'min:1'],
            'rate_notes'           => ['nullable', 'string', 'max:255'],

            'repayment_mode' => ['nullable', 'in:once,installment'],
            'duration_months' => ['nullable', 'integer', 'min:1'],

            'guarantors' => ['array'],
            'guarantors.*.user_id' => ['required_with:guarantors', 'integer', 'exists:users,id'],
            'guarantors.*.amount' => ['required_with:guarantors', 'numeric', 'min:1'],
        ]);

        $loan = $this->loanService->disburse(
            memberId: (int) $data['user_id'],
            principal: (float) $data['principal'],
            dueDate: (string) $data['due_date'],
            recordedBy: (int) $user->id,
            repaymentMode: $data['repayment_mode'] ?? null,
            durationMonths: $data['duration_months'] ?? null,
            guarantors: $data['guarantors'] ?? [],

            interestRate: (float) $data['interest_rate'],
            interestBasis: (string) $data['interest_basis'],
            interestTermMonths: $data['interest_term_months'] ?? null,
            rateNotes: $data['rate_notes'] ?? null
        );

        return response()->json([
            'message' => 'Loan disbursed successfully.',
            'data' => $loan->load('user:id,name,email,phone'),
        ], 201);
    }

    /**
     * ✅ Backward compatible:
     * api.php uses POST /loans -> disburse
     */
    public function disburse(Request $request)
    {
        return $this->store($request);
    }

    /**
     * POST /loans/{loan}/repay
     * api.php expects: LoanController@repayWithAutoSplit
     */
    public function repayWithAutoSplit(Request $request, Loan $loan)
    {
        // keep your old repay behavior but under the route name expected by api.php
        return $this->repay($request, $loan);
    }

    /**
     * POST /loans/{loan}/repay
     * Repayment with auto-split into contribution
     */
    public function repay(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $result = $this->loanService->repayWithAutoSplit(
            loanId: (int) $loan->id,
            amount: (float) $data['amount'],
            paidDate: (string) $data['paid_date'],
            recordedBy: (int) $user->id
        );

        return response()->json($result);
    }

    /**
     * GET /loans/{loan}/top-up/preview
     */
    public function topUpPreview(Request $request, Loan $loan)
    {
        $user = $request->user();

        $data = $this->loanService->topUpPreview((int) $loan->id, $user);

        return response()->json([
            'allowed' => (bool)($data['allowed'] ?? false),
            'reason' => $data['reason'] ?? null,
            'data' => $data,
        ]);
    }

    /**
     * POST /loans/{loan}/top-up
     */
    public function topUp(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date'],

            'interest_rate'        => ['required', 'numeric', 'min:0.01'],
            'interest_basis'       => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['nullable', 'integer', 'min:1'],
            'rate_notes'           => ['nullable', 'string', 'max:255'],
        ]);

        $newLoan = $this->loanService->topUpAsNewLoan(
            baseLoanId: (int) $loan->id,
            topUpAmount: (float) $data['amount'],
            dueDate: (string) $data['due_date'],
            recordedBy: (int) $user->id,
            interestRate: (float) $data['interest_rate'],
            interestBasis: (string) $data['interest_basis'],
            interestTermMonths: $data['interest_term_months'] ?? null,
            rateNotes: $data['rate_notes'] ?? null
        );

        return response()->json([
            'message' => 'Top-up loan created.',
            'data' => $newLoan->load('user:id,name,email,phone'),
        ], 201);
    }


    /**
     * GET /members/{user}/loans
     * Must return a list of loans for member (self or admin/treasurer)
     */
    public function memberLoans(Request $request, User $user)
    {
        $viewer = $request->user();

        $isPrivileged = in_array($viewer->role, ['admin', 'treasurer'], true);
        if (!$isPrivileged && (int)$viewer->id !== (int)$user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status  = $request->filled('status') ? (string) $request->query('status') : null;
        $perPage = (int) ($request->query('per_page', 15));
        $perPage = max(1, min(100, $perPage));

        $loans = $this->loanService->listLoans((int)$user->id, $status, $perPage);

        return response()->json($loans);
    }

    /**
     * GET /members/{user}/loans/summary
     * Optional: ?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function memberSummary(Request $request, User $user)
    {
        $viewer = $request->user();
        $from = $request->query('from');
        $to   = $request->query('to');

        try {
            $data = $this->loanService->memberLoanSummary(
                viewer: $viewer,
                member: $user,
                from: $from,
                to: $to
            );

            return response()->json([
                'message' => 'Member loan summary',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to load member summary.',
            ], 422);
        }
    }
    public function insights(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'status'  => ['nullable', 'string', 'in:pending,approved,active,completed,defaulted'],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
        ]);

        $tz = 'Africa/Kigali';
        $today = Carbon::today($tz)->toDateString();
        $dueSoon = Carbon::today($tz)->addDays(7)->toDateString();

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $status = isset($data['status']) ? (string)$data['status'] : null;

        $from = !empty($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : null;
        $to   = !empty($data['to'])   ? Carbon::parse($data['to'], $tz)->endOfDay()   : null;

        // Sum repayments per loan
        $repaySub = DB::table('loan_repayments')
            ->select('loan_id', DB::raw('SUM(amount) as repaid_sum'))
            ->groupBy('loan_id');

        // Base dataset: loans + repaid_sum + computed outstanding
        $base = DB::table('loans')
            ->leftJoinSub($repaySub, 'r', function ($join) {
                $join->on('loans.id', '=', 'r.loan_id');
            })
            ->when($userId, fn($q) => $q->where('loans.user_id', $userId))
            ->when($status, fn($q) => $q->where('loans.status', $status))
            ->when($from, fn($q) => $q->where('loans.created_at', '>=', $from))
            ->when($to, fn($q) => $q->where('loans.created_at', '<=', $to))
            ->selectRaw('
            loans.id,
            loans.user_id,
            loans.status,
            loans.principal,
            loans.total_payable,
            loans.interest_rate,
            loans.repayment_mode,
            loans.monthly_installment,
            loans.due_date,
            loans.created_at,
            COALESCE(r.repaid_sum, 0) as repaid_sum,
            GREATEST(loans.total_payable - COALESCE(r.repaid_sum, 0), 0) as outstanding_calc
        ');

        // Aggregate over the base dataset (now we can sum outstanding_calc etc.)
        $agg = DB::query()
            ->fromSub($base, 'x')
            ->selectRaw('
            COUNT(*) as loans_count,

            SUM(x.principal) as principal_total,
            SUM(x.total_payable) as total_payable_total,
            SUM(GREATEST(x.total_payable - x.principal, 0)) as interest_total,
            AVG(x.principal) as avg_principal,

            SUM(CASE WHEN x.status = "active" THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN x.status = "completed" THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN x.status = "defaulted" THEN 1 ELSE 0 END) as defaulted_count,

            SUM(CASE WHEN x.repayment_mode = "installment" THEN 1 ELSE 0 END) as installment_count,
            AVG(CASE WHEN x.repayment_mode = "installment" AND x.monthly_installment > 0 THEN x.monthly_installment ELSE NULL END) as avg_installment,

            SUM(CASE WHEN x.status = "active" THEN x.outstanding_calc ELSE 0 END) as outstanding_total,

            SUM(
                CASE
                    WHEN x.status = "active"
                     AND x.due_date IS NOT NULL
                     AND x.due_date < ?
                     AND x.outstanding_calc > 0
                    THEN 1 ELSE 0
                END
            ) as overdue_count,

            SUM(
                CASE
                    WHEN x.status = "active"
                     AND x.due_date IS NOT NULL
                     AND x.due_date >= ?
                     AND x.due_date <= ?
                     AND x.outstanding_calc > 0
                    THEN 1 ELSE 0
                END
            ) as due_soon_count,

            (SUM(x.interest_rate * x.principal) / NULLIF(SUM(x.principal), 0)) as weighted_avg_interest_rate
        ', [$today, $today, $dueSoon])
            ->first();

        $loansCount = (int)($agg->loans_count ?? 0);
        $completedCount = (int)($agg->completed_count ?? 0);
        $defaultedCount = (int)($agg->defaulted_count ?? 0);

        $completionRate = $loansCount > 0 ? round(($completedCount / $loansCount) * 100) : 0;
        $defaultRate    = $loansCount > 0 ? round(($defaultedCount / $loansCount) * 100) : 0;

        return response()->json([
            'message' => 'Loan insights',
            'filters' => [
                'user_id' => $userId,
                'status'  => $status,
                'from'    => $data['from'] ?? null,
                'to'      => $data['to'] ?? null,
                'due_soon_window_days' => 7,
            ],
            'data' => [
                'counts' => [
                    'loans'       => $loansCount,
                    'active'      => (int)($agg->active_count ?? 0),
                    'completed'   => $completedCount,
                    'defaulted'   => $defaultedCount,
                    'overdue'     => (int)($agg->overdue_count ?? 0),
                    'due_soon'    => (int)($agg->due_soon_count ?? 0),
                    'installment' => (int)($agg->installment_count ?? 0),
                ],
                'totals' => [
                    'principal_disbursed' => round((float)($agg->principal_total ?? 0), 2),
                    'total_payable'       => round((float)($agg->total_payable_total ?? 0), 2),
                    'interest_computed'   => round((float)($agg->interest_total ?? 0), 2),
                    'outstanding_active'  => round((float)($agg->outstanding_total ?? 0), 2),
                ],
                'averages' => [
                    'avg_principal'               => round((float)($agg->avg_principal ?? 0), 2),
                    'avg_installment'             => round((float)($agg->avg_installment ?? 0), 2),
                    'weighted_avg_interest_rate'  => round((float)($agg->weighted_avg_interest_rate ?? 0), 4),
                ],
                'rates' => [
                    'completion_rate_pct' => $completionRate,
                    'default_rate_pct'    => $defaultRate,
                ],
            ],
        ]);
    }
    public function repayPreview(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $data = $this->loanService->repayPreview(loanId: (int) $loan->id, viewer: $user);

            return response()->json([
                'message' => 'Repayment preview',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to load repayment preview.',
            ], 422);
        }
    }
}
