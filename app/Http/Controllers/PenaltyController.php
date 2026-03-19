<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Penalty;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\PenaltyService;
use App\Http\Controllers\Controller;
use App\Services\PenaltyReportService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Http\Requests\Penalties\ResolvePenaltyRequest;
use App\Http\Requests\Penalties\StoreManualPenaltyRequest;

class PenaltyController extends Controller
{
    public function __construct(
        protected PenaltyService $penaltyService,
        protected PenaltyReportService $reportService
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

    protected function penaltyRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
        ];
    }

    /**
     * GET /api/penalties?owner_type=&user_id=&beneficiary_id=&status=&source_type=&from=&to=
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'owner_type' => ['nullable', 'in:user,beneficiary'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'status' => ['nullable', 'in:unpaid,paid,waived'],
            'source_type' => ['nullable', 'in:contribution,loan,loan_installment,manual'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $data = $this->reportService->list(
            filters: $validated,
            perPage: (int) ($validated['perPage'] ?? 15)
        );

        return response()->json([
            'message' => 'OK',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/members/{user}/penalties/summary?from=&to=
     * Kept user-based for compatibility.
     */
    public function memberSummary(Request $request, User $user)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        try {
            $data = $this->reportService->memberSummary(
                viewer: $request->user(),
                member: $user,
                from: $request->query('from'),
                to: $request->query('to'),
            );

            return response()->json($data);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
    }

    /**
     * GET /api/members/{user}/penalties?status=&from=&to=
     * Kept user-based for compatibility.
     */
    public function memberPenalties(Request $request, User $user)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['unpaid', 'paid', 'waived'])],
            'from'   => ['nullable', 'date'],
            'to'     => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        try {
            $data = $this->reportService->memberPenalties(
                viewer: $request->user(),
                member: $user,
                filters: $request->only(['status', 'from', 'to']),
                perPage: (int) ($request->input('perPage') ?? 15)
            );

            return response()->json([
                'message' => 'OK',
                'data' => $data,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
    }

    /**
     * POST /api/penalties/manual
     */
    public function storeManual(StoreManualPenaltyRequest $request)
    {
        ['userId' => $userId, 'beneficiaryId' => $beneficiaryId] = $this->resolveOwnerFromRequest($request);

        $penalty = $this->penaltyService->manual(
            userId: $userId,
            beneficiaryId: $beneficiaryId,
            amount: (float) $request->input('amount'),
            reason: $request->input('reason'),
            recordedBy: (int) $request->user()->id,
            date: $request->input('date')
        );

        return response()->json([
            'message' => 'Penalty created successfully',
            'data' => $penalty?->load($this->penaltyRelations()),
        ], 201);
    }

    /**
     * PATCH /api/penalties/{penalty}/pay
     */
    public function pay(ResolvePenaltyRequest $request, Penalty $penalty)
    {
        try {
            $updated = $this->penaltyService->markPaid(
                penaltyId: $penalty->id,
                resolvedBy: (int) $request->user()->id,
                date: $request->input('date')
            );

            return response()->json([
                'message' => 'Penalty marked as paid',
                'data' => $updated->load($this->penaltyRelations()),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * PATCH /api/penalties/{penalty}/waive
     */
    public function waive(ResolvePenaltyRequest $request, Penalty $penalty)
    {
        try {
            $updated = $this->penaltyService->waive(
                penaltyId: $penalty->id,
                resolvedBy: (int) $request->user()->id,
                date: $request->input('date')
            );

            return response()->json([
                'message' => 'Penalty waived',
                'data' => $updated->load($this->penaltyRelations()),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}