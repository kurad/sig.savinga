<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    public function __construct(
        protected LoanService $loanService
    ) {}

    protected function resolveOwnerFromRequest(Request $request): array
    {
        $ownerType = $request->input('owner_type');

        return [
            'owner_type' => $ownerType,
            'userId' => $ownerType === 'user' ? $request->integer('user_id') : null,
            'beneficiaryId' => $ownerType === 'beneficiary' ? $request->integer('beneficiary_id') : null,
        ];
    }

    protected function loanRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'owner_type' => ['nullable', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 15);

        return response()->json(
            $this->loanService->listLoans(
                userId: $data['owner_type'] === 'user' ? ($data['user_id'] ?? null) : ($data['user_id'] ?? null),
                beneficiaryId: $data['owner_type'] === 'beneficiary' ? ($data['beneficiary_id'] ?? null) : ($data['beneficiary_id'] ?? null),
                status: $data['status'] ?? null,
                perPage: $perPage
            )
        );
    }

    public function eligibility(Request $request)
    {
        $viewer = $request->user();

        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],

            'principal' => ['required', 'numeric', 'min:1'],
            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_basis' => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['required_if:interest_basis,per_term', 'nullable', 'integer', 'min:1'],
            'repayment_mode' => ['nullable', 'in:once,installment'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        try {
            $repaymentMode = $data['repayment_mode'] ?? 'once';

            $e = $this->loanService->eligibilityPreview(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                principal: (float) $data['principal'],
                viewer: $viewer,
                interestRate: (float) $data['interest_rate'],
                interestBasis: (string) $data['interest_basis'],
                interestTermMonths: ($data['interest_basis'] === 'per_term')
                    ? (int) $data['interest_term_months']
                    : null,
                repaymentMode: $repaymentMode,
                durationMonths: ($repaymentMode === 'installment')
                    ? (int) ($data['duration_months'] ?? 1)
                    : 1
            );

            $allowed = !(bool) ($e['blocked'] ?? false);
            $reason = $allowed ? null : implode(' ', (array) ($e['blocked_reasons'] ?? ['Not eligible.']));

            return response()->json([
                'allowed' => $allowed,
                'reason' => $reason,
                'data' => [
                    'eligibility' => [
                        'savings_base' => (float) ($e['savings_base'] ?? 0),
                        'max_allowed' => (float) ($e['max_allowed'] ?? 0),
                        'requested_principal' => (float) ($e['requested_principal'] ?? 0),
                        'shortfall' => (float) ($e['shortfall'] ?? 0),
                        'requires_guarantor' => (bool) ($e['requires_guarantor'] ?? false),
                        'months_contributed' => (int) ($e['months_contributed'] ?? 0),
                        'has_active_loan' => (bool) ($e['has_active_loan'] ?? false),
                        'blocked' => (bool) ($e['blocked'] ?? false),
                        'blocked_reasons' => (array) ($e['blocked_reasons'] ?? []),
                    ],
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
        $viewer = $request->user();

        if (!in_array($viewer->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],

            'principal' => ['required', 'numeric', 'min:1'],
            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_basis' => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['required_if:interest_basis,per_term', 'nullable', 'integer', 'min:1'],
            'repayment_mode' => ['nullable', 'in:once,installment'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
        ]);

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        try {
            $preview = $this->loanService->disbursePreview(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                principal: (float) $data['principal'],
                viewer: $viewer,
                interestRate: (float) $data['interest_rate'],
                interestBasis: (string) $data['interest_basis'],
                interestTermMonths: ($data['interest_basis'] === 'per_term')
                    ? (int) $data['interest_term_months']
                    : null,
                repaymentMode: $data['repayment_mode'] ?? null,
                durationMonths: $data['duration_months'] ?? null
            );

            return response()->json([
                'message' => 'Disburse preview',
                'data' => $preview,
            ]);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to load disburse preview.',
            ], 422);
        }
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'owner_type' => ['required', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'required_if:owner_type,user', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'required_if:owner_type,beneficiary', 'integer', 'exists:beneficiaries,id'],

            'principal' => ['required', 'numeric', 'min:1'],
            'due_date' => ['nullable', 'date'],

            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_basis' => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['required_if:interest_basis,per_term', 'nullable', 'integer', 'min:1'],
            'rate_notes' => ['nullable', 'string', 'max:255'],

            'repayment_mode' => ['nullable', 'in:once,installment'],
            'duration_months' => ['nullable', 'integer', 'min:1'],

            'guarantors' => ['array'],
            'guarantors.*.user_id' => ['required_with:guarantors', 'integer', 'exists:users,id'],
            'guarantors.*.amount' => ['required_with:guarantors', 'numeric', 'min:1'],
        ]);

        $repaymentMode = $data['repayment_mode'] ?? 'once';
        if ($repaymentMode === 'once' && empty($data['due_date'])) {
            return response()->json(['message' => 'due_date is required for once loans.'], 422);
        }

        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        try {
            $loan = $this->loanService->disburse(
                userId: $userId,
                beneficiaryId: $beneficiaryId,
                principal: (float) $data['principal'],
                dueDate: (string) ($data['due_date'] ?? now('Africa/Kigali')->toDateString()),
                recordedBy: (int) $user->id,
                repaymentMode: $data['repayment_mode'] ?? null,
                durationMonths: $data['duration_months'] ?? null,
                guarantors: $data['guarantors'] ?? [],
                interestRate: (float) $data['interest_rate'],
                interestBasis: (string) $data['interest_basis'],
                interestTermMonths: ($data['interest_basis'] === 'per_term')
                    ? (int) $data['interest_term_months']
                    : null,
                rateNotes: $data['rate_notes'] ?? null
            );

            return response()->json([
                'message' => 'Loan disbursed successfully.',
                'data' => $loan->load($this->loanRelations()),
            ], 201);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function disburse(Request $request)
    {
        return $this->store($request);
    }

    public function repayWithAutoSplit(Request $request, Loan $loan)
    {
        return $this->repay($request, $loan);
    }

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

        try {
            $result = $this->loanService->repayWithAutoSplit(
                loanId: (int) $loan->id,
                amount: (float) $data['amount'],
                paidDate: (string) $data['paid_date'],
                recordedBy: (int) $user->id
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function topUpPreview(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $requestedTotal = $request->filled('requested_total')
            ? round((float) $request->query('requested_total'), 2)
            : null;

        try {
            $preview = $this->loanService->topUpRefinancePreview(
                baseLoanId: (int) $loan->id,
                viewer: $user,
                requestedTotal: $requestedTotal
            );

            $payload = $preview['data'] ?? [];
            $elig = $payload['eligibility'] ?? [];

            $baseOutstanding = (float) ($elig['base_outstanding'] ?? ($payload['loan']['outstanding_balance'] ?? 0));
            $maxNewTotal = (float) ($elig['max_new_total_allowed'] ?? ($elig['headroom'] ?? 0));

            $elig['base_outstanding'] = round($baseOutstanding, 2);
            $elig['max_new_total_allowed'] = round($maxNewTotal, 2);
            $elig['cash_out_headroom'] = round(max(0, $maxNewTotal - $baseOutstanding), 2);

            if ($requestedTotal !== null) {
                $elig['requested_new_total'] = $requestedTotal;
                $elig['cash_out_if_requested'] = round(max(0, $requestedTotal - $baseOutstanding), 2);
            }

            $payload['eligibility'] = $elig;

            return response()->json([
                'allowed' => (bool) ($preview['allowed'] ?? false),
                'reason' => $preview['reason'] ?? null,
                'data' => [
                    'loan' => $payload['loan'] ?? [],
                    'rules' => $payload['rules'] ?? [],
                    'eligibility' => $payload['eligibility'] ?? [],
                ],
            ]);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'allowed' => false,
                'reason' => $e->getMessage(),
                'data' => [
                    'loan' => [
                        'id' => (int) $loan->id,
                        'user_id' => $loan->user_id,
                        'beneficiary_id' => $loan->beneficiary_id,
                        'status' => (string) $loan->status,
                        'repayment_mode' => (string) ($loan->repayment_mode ?? 'once'),
                        'installments_paid' => method_exists($loan, 'installmentsPaidCount')
                            ? (int) $loan->installmentsPaidCount()
                            : 0,
                        'outstanding_balance' => round((float) $loan->outstandingBalance(), 2),
                        'due_date' => $loan->due_date ? Carbon::parse($loan->due_date)->toDateString() : null,
                    ],
                    'rules' => [],
                    'eligibility' => [
                        'base_outstanding' => round((float) $loan->outstandingBalance(), 2),
                        'max_new_total_allowed' => 0,
                        'cash_out_headroom' => 0,
                    ],
                ],
            ], 422);
        }
    }

    public function topUp(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'requested_total' => ['required', 'numeric', 'min:1'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],

            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_basis' => ['required', 'in:per_month,per_year,per_term'],
            'interest_term_months' => ['required_if:interest_basis,per_term', 'nullable', 'integer', 'min:1'],
            'rate_notes' => ['nullable', 'string', 'max:255'],
        ]);

        $baseOutstanding = round((float) $loan->outstandingBalance(), 2);
        $requestedTotal = round((float) $data['requested_total'], 2);

        if ($requestedTotal <= $baseOutstanding) {
            return response()->json([
                'message' => 'Requested new loan total must be greater than the outstanding balance ('
                    . number_format($baseOutstanding, 2) . ').',
            ], 422);
        }

        try {
            $res = $this->loanService->topUpRefinance(
                baseLoanId: (int) $loan->id,
                newPrincipalTotal: (float) $requestedTotal,
                durationMonths: (int) $data['duration_months'],
                recordedBy: (int) $user->id,
                interestRate: (float) $data['interest_rate'],
                interestBasis: (string) $data['interest_basis'],
                interestTermMonths: ($data['interest_basis'] === 'per_term')
                    ? (int) $data['interest_term_months']
                    : null,
                rateNotes: $data['rate_notes'] ?? null
            );

            return response()->json([
                'message' => $res['message'] ?? 'Top-up refinance created.',
                'data' => $res,
            ], 201);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to create top-up refinance.',
            ], 422);
        }
    }

    public function memberLoans(Request $request, User $user)
    {
        $viewer = $request->user();

        $isPrivileged = in_array($viewer->role, ['admin', 'treasurer'], true);
        if (!$isPrivileged && (int) $viewer->id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = $request->filled('status') ? (string) $request->query('status') : null;

        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min(100, $perPage));

        return response()->json(
            $this->loanService->listLoans(
                userId: (int) $user->id,
                beneficiaryId: null,
                status: $status,
                perPage: $perPage
            )
        );
    }

    public function memberSummary(Request $request, User $user)
    {
        $viewer = $request->user();

        try {
            $data = $this->loanService->memberLoanSummary(
                viewer: $viewer,
                member: $user,
                from: $request->query('from'),
                to: $request->query('to')
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
            'owner_type' => ['nullable', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'status' => ['nullable', 'string', 'in:active,completed,defaulted'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $tz = 'Africa/Kigali';
        $today = Carbon::today($tz)->toDateString();
        $dueSoon = Carbon::today($tz)->addDays(7)->toDateString();

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $beneficiaryId = isset($data['beneficiary_id']) ? (int) $data['beneficiary_id'] : null;
        $status = isset($data['status']) ? (string) $data['status'] : null;

        $from = !empty($data['from']) ? Carbon::parse($data['from'], $tz)->startOfDay() : null;
        $to = !empty($data['to']) ? Carbon::parse($data['to'], $tz)->endOfDay() : null;

        $repaySub = DB::table('loan_repayments')
            ->select('loan_id', DB::raw('SUM(amount) as repaid_sum'))
            ->groupBy('loan_id');

        $base = DB::table('loans')
            ->leftJoinSub($repaySub, 'r', function ($join) {
                $join->on('loans.id', '=', 'r.loan_id');
            })
            ->when($data['owner_type'] === 'user' && $userId, fn ($q) => $q->where('loans.user_id', $userId))
            ->when($data['owner_type'] === 'beneficiary' && $beneficiaryId, fn ($q) => $q->where('loans.beneficiary_id', $beneficiaryId))
            ->when(empty($data['owner_type']) && $userId, fn ($q) => $q->where('loans.user_id', $userId))
            ->when(empty($data['owner_type']) && $beneficiaryId, fn ($q) => $q->where('loans.beneficiary_id', $beneficiaryId))
            ->when($status, fn ($q) => $q->where('loans.status', $status))
            ->when($from, fn ($q) => $q->where('loans.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('loans.created_at', '<=', $to))
            ->selectRaw('
                loans.id,
                loans.user_id,
                loans.beneficiary_id,
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

        $loansCount = (int) ($agg->loans_count ?? 0);
        $completedCount = (int) ($agg->completed_count ?? 0);
        $defaultedCount = (int) ($agg->defaulted_count ?? 0);

        $completionRate = $loansCount > 0 ? round(($completedCount / $loansCount) * 100) : 0;
        $defaultRate = $loansCount > 0 ? round(($defaultedCount / $loansCount) * 100) : 0;

        return response()->json([
            'message' => 'Loan insights',
            'filters' => [
                'owner_type' => $data['owner_type'] ?? null,
                'user_id' => $userId,
                'beneficiary_id' => $beneficiaryId,
                'status' => $status,
                'from' => $data['from'] ?? null,
                'to' => $data['to'] ?? null,
                'due_soon_window_days' => 7,
            ],
            'data' => [
                'counts' => [
                    'loans' => $loansCount,
                    'active' => (int) ($agg->active_count ?? 0),
                    'completed' => $completedCount,
                    'defaulted' => $defaultedCount,
                    'overdue' => (int) ($agg->overdue_count ?? 0),
                    'due_soon' => (int) ($agg->due_soon_count ?? 0),
                    'installment' => (int) ($agg->installment_count ?? 0),
                ],
                'totals' => [
                    'principal_disbursed' => round((float) ($agg->principal_total ?? 0), 2),
                    'total_payable' => round((float) ($agg->total_payable_total ?? 0), 2),
                    'interest_computed' => round((float) ($agg->interest_total ?? 0), 2),
                    'outstanding_active' => round((float) ($agg->outstanding_total ?? 0), 2),
                ],
                'averages' => [
                    'avg_principal' => round((float) ($agg->avg_principal ?? 0), 2),
                    'avg_installment' => round((float) ($agg->avg_installment ?? 0), 2),
                    'weighted_avg_interest_rate' => round((float) ($agg->weighted_avg_interest_rate ?? 0), 4),
                ],
                'rates' => [
                    'completion_rate_pct' => $completionRate,
                    'default_rate_pct' => $defaultRate,
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
            $data = $this->loanService->repayPreview(
                loanId: (int) $loan->id,
                viewer: $user
            );

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