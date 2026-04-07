<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\OpeningBalance;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $service) {}

    protected function resolveOwnerUserId(?int $userId, ?int $beneficiaryId): int
    {
        if (!empty($userId)) {
            return (int) $userId;
        }

        if (!empty($beneficiaryId)) {
            $beneficiary = Beneficiary::findOrFail($beneficiaryId);

            if (empty($beneficiary->guardian_user_id)) {
                throw ValidationException::withMessages([
                    'beneficiary_id' => ['Selected beneficiary has no guardian user linked.'],
                ]);
            }

            return (int) $beneficiary->guardian_user_id;
        }

        throw ValidationException::withMessages([
            'participant' => ['Either user_id or beneficiary_id is required.'],
        ]);
    }

    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'as_of_period'   => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
            'amount'         => ['required', 'numeric'],
            'note'           => ['nullable', 'string', 'max:255'],
        ]);
    }

    public function showByUser(Request $request, int $userId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $q = OpeningBalance::query()
            ->with('adjustments')
            ->where('user_id', $userId)
            ->whereNull('beneficiary_id');

        if ($request->filled('as_of_period')) {
            $q->where('as_of_period', $request->as_of_period);
        } else {
            $q->orderByDesc('as_of_period')->orderByDesc('id');
        }

        $row = $q->first();

        if (!$row) {
            return response()->json([
                'data' => null,
            ]);
        }

        $adjustmentsTotal = round((float) $row->adjustments->sum('amount'), 2);
        $originalAmount = round((float) $row->amount, 2);
        $effectiveAmount = round($originalAmount + $adjustmentsTotal, 2);

        return response()->json([
            'data' => [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'beneficiary_id' => $row->beneficiary_id,
                'as_of_period' => $row->as_of_period,
                'amount' => $originalAmount,
                'original_amount' => $originalAmount,
                'adjustments_total' => $adjustmentsTotal,
                'effective_amount' => $effectiveAmount,
                'note' => $row->note,
                'transaction_id' => $row->transaction_id,
                'created_by' => $row->created_by,
                'created_at' => $row->created_at,
                'adjustments' => $row->adjustments->map(function ($adj) {
                    return [
                        'id' => $adj->id,
                        'amount' => round((float) $adj->amount, 2),
                        'reason' => $adj->reason,
                        'created_by' => $adj->created_by,
                        'created_at' => $adj->created_at,
                    ];
                })->values(),
            ],
        ]);
    }

    public function showByBeneficiary(Request $request, int $beneficiaryId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $beneficiary = Beneficiary::findOrFail($beneficiaryId);

        $q = OpeningBalance::query()
            ->with('adjustments')
            ->where('user_id', $beneficiary->guardian_user_id)
            ->where('beneficiary_id', $beneficiaryId);

        if ($request->filled('as_of_period')) {
            $q->where('as_of_period', $request->as_of_period);
        } else {
            $q->orderByDesc('as_of_period')->orderByDesc('id');
        }

        $item = $q->first();

        if (!$item) {
            return response()->json([
                'message' => 'Opening balance not found for this beneficiary.',
            ], 404);
        }

        $adjustmentsTotal = round((float) $item->adjustments->sum('amount'), 2);
        $originalAmount = round((float) $item->amount, 2);
        $effectiveAmount = round($originalAmount + $adjustmentsTotal, 2);

        return response()->json([
            'data' => [
                'id' => $item->id,
                'user_id' => $item->user_id,
                'beneficiary_id' => $item->beneficiary_id,
                'as_of_period' => $item->as_of_period,
                'amount' => $originalAmount,
                'original_amount' => $originalAmount,
                'adjustments_total' => $adjustmentsTotal,
                'effective_amount' => $effectiveAmount,
                'note' => $item->note,
                'transaction_id' => $item->transaction_id,
                'created_by' => $item->created_by,
                'created_at' => $item->created_at,
                'adjustments' => $item->adjustments->map(function ($adj) {
                    return [
                        'id' => $adj->id,
                        'amount' => round((float) $adj->amount, 2),
                        'reason' => $adj->reason,
                        'created_by' => $adj->created_by,
                        'created_at' => $adj->created_at,
                    ];
                })->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);

        if (empty($validated['user_id']) && empty($validated['beneficiary_id'])) {
            throw ValidationException::withMessages([
                'participant' => ['Either user_id or beneficiary_id is required.'],
            ]);
        }

        $validated['user_id'] = $this->resolveOwnerUserId(
            $validated['user_id'] ?? null,
            $validated['beneficiary_id'] ?? null
        );

        $adminId = (int) auth()->id();

        $row = $this->service->setOpeningBalance($validated, $adminId);

        return response()->json([
            'message' => 'Opening balance saved successfully.',
            'data'    => $row,
        ], 201);
    }

    public function update(Request $request, OpeningBalance $openingBalance)
    {
        return response()->json([
            'message' => 'Opening balances cannot be edited directly after posting. Please use an adjustment/correction flow.',
        ], 422);
    }

    public function myOpeningBalance(Request $request)
    {
        $me = $request->user();

        $opening = OpeningBalance::query()
            ->where('user_id', $me->id)
            ->whereNull('beneficiary_id')
            ->latest('as_of_period')
            ->first();

        return response()->json([
            'message' => 'OK',
            'data' => $opening ? [
                'set' => true,
                'amount' => (float) $opening->amount,
                'as_of_period' => $opening->as_of_period,
            ] : [
                'set' => false,
                'amount' => 0,
                'as_of_period' => null,
            ],
        ]);
    }
}
