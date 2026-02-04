<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\LoanService;

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

        if (!in_array($user->role, ['admin', 'treasurer', 'chair'], true)) {
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
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer', 'chair'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id'   => ['required', 'integer', 'exists:users,id'],
            'principal' => ['required', 'numeric', 'min:1'],
        ]);

        $preview = $this->loanService->disbursePreview(
            memberId: (int) $data['user_id'],
            principal: (float) $data['principal'],
            viewer: $user
        );

        // UI-friendly shape:
        // allowed = not blocked (even if guarantor required)
        $allowed = !(bool)($preview['eligibility']['blocked'] ?? false);

        $reason = null;
        if (!$allowed) {
            $reasons = (array)($preview['eligibility']['blocked_reasons'] ?? []);
            $reason = count($reasons) ? implode(' ', $reasons) : 'Not eligible.';
        }

        return response()->json([
            'allowed' => $allowed,
            'reason' => $reason,
            'data' => [
                'member' => $preview['member'] ?? [],
                'rules' => $preview['rules'] ?? [],
                'totals' => $preview['totals'] ?? [],
                'eligibility' => array_merge(($preview['eligibility'] ?? []), [
                    'total_contributions' => $preview['totals']['savings_base'] ?? 0, // (optional alias for UI)
                    'active_loan_exposure' => ($preview['totals']['has_active_loan'] ?? false) ? 1 : 0, // your UI expects a number; adjust if you add real exposure
                ]),
                'preview' => $preview['preview'] ?? [],
                'guarantor_candidates' => $preview['guarantor_candidates'] ?? [],
            ],
        ]);
    }

    /**
     * POST /loans
     * Disburse loan (recommended REST endpoint)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer', 'chair'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'principal' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date'],

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
            guarantors: $data['guarantors'] ?? []
        );

        return response()->json([
            'message' => 'Loan disbursed successfully.',
            'data' => $loan->load('user:id,name,email,phone'),
        ], 201);
    }

    /**
     * ✅ Backward compatible:
     * If your old route is POST /loans/disburse -> LoanController@disburse
     * this keeps it working.
     */
    public function disburse(Request $request)
    {
        return $this->store($request);
    }

    /**
     * POST /loans/{loan}/repay
     * Repayment with auto-split into contribution (your UI expects this behavior)
     */
    public function repay(Request $request, Loan $loan)
    {
        $user = $request->user();

        if (!in_array($user->role, ['admin', 'treasurer', 'chair'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'paid_date' => ['required', 'date'],
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

        if (!in_array($user->role, ['admin', 'treasurer', 'chair'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'due_date' => ['required', 'date'],
        ]);

        $newLoan = $this->loanService->topUpAsNewLoan(
            baseLoanId: (int) $loan->id,
            topUpAmount: (float) $data['amount'],
            dueDate: (string) $data['due_date'],
            recordedBy: (int) $user->id
        );

        return response()->json([
            'message' => 'Top-up loan created.',
            'data' => $newLoan->load('user:id,name,email,phone'),
        ], 201);
    }
    public function memberLoans(Request $request, User $member)
    {
        $viewer = $request->user();

        $from = $request->query('from');
        $to   = $request->query('to');

        try {
            $data = $this->loanService->memberLoanSummary(
                viewer: $viewer,
                member: $member,
                from: $from,
                to: $to
            );

            return response()->json([
                'message' => 'Member loan summary',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            // memberLoanSummary throws 'Forbidden' for unauthorized viewers
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to load member loans.',
            ], 422);
        }
    }
    /**
     * GET /members/{member}/loan-summary
     * Optional: ?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function memberSummary(Request $request, User $member)
    {
        $viewer = $request->user();
        $from = $request->query('from');
        $to   = $request->query('to');

        try {
            $data = $this->loanService->memberLoanSummary(
                viewer: $viewer,
                member: $member,
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
}
