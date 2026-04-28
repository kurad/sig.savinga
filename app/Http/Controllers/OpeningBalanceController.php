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

            // Allows positive and negative opening balances, but blocks zero.
            'amount'         => ['required', 'numeric', 'not_in:0'],

            'note'           => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function formatOpeningBalance(?OpeningBalance $opening): ?array
    {
        if (!$opening) {
            return null;
        }

        if (!$opening->relationLoaded('adjustments')) {
            $opening->load('adjustments');
        }

        $originalAmount = round((float) $opening->amount, 2);
        $adjustmentsTotal = round((float) $opening->adjustments->sum('amount'), 2);
        $effectiveAmount = round($originalAmount + $adjustmentsTotal, 2);

        return [
            'id' => $opening->id,
            'user_id' => $opening->user_id,
            'beneficiary_id' => $opening->beneficiary_id,
            'as_of_period' => $opening->as_of_period,

            // Keep amount as effective amount for frontend display.
            'amount' => $effectiveAmount,

            // Keep clear accounting fields.
            'original_amount' => $originalAmount,
            'adjustments_total' => $adjustmentsTotal,
            'effective_amount' => $effectiveAmount,

            'note' => $opening->note,
            'transaction_id' => $opening->transaction_id,
            'created_by' => $opening->created_by,
            'created_at' => $opening->created_at,

            'adjustments' => $opening->adjustments->map(function ($adj) {
                return [
                    'id' => $adj->id,
                    'amount' => round((float) $adj->amount, 2),
                    'reason' => $adj->reason,
                    'created_by' => $adj->created_by,
                    'created_at' => $adj->created_at,
                ];
            })->values(),
        ];
    }

    public function showByUser(Request $request, int $userId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $query = OpeningBalance::query()
            ->with('adjustments')
            ->where('user_id', $userId)
            ->whereNull('beneficiary_id');

        if ($request->filled('as_of_period')) {
            $query->where('as_of_period', $request->as_of_period);
        } else {
            $query->orderByDesc('as_of_period')->orderByDesc('id');
        }

        $opening = $query->first();

        return response()->json([
            'data' => $this->formatOpeningBalance($opening),
        ]);
    }

    public function showByBeneficiary(Request $request, int $beneficiaryId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $beneficiary = Beneficiary::findOrFail($beneficiaryId);

        if (empty($beneficiary->guardian_user_id)) {
            throw ValidationException::withMessages([
                'beneficiary_id' => ['Selected beneficiary has no guardian user linked.'],
            ]);
        }

        $query = OpeningBalance::query()
            ->with('adjustments')
            ->where('user_id', $beneficiary->guardian_user_id)
            ->where('beneficiary_id', $beneficiaryId);

        if ($request->filled('as_of_period')) {
            $query->where('as_of_period', $request->as_of_period);
        } else {
            $query->orderByDesc('as_of_period')->orderByDesc('id');
        }

        $opening = $query->first();

        return response()->json([
            'data' => $this->formatOpeningBalance($opening),
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

        $opening = $this->service->setOpeningBalance($validated, $adminId);

        $opening->load('adjustments');

        return response()->json([
            'message' => 'Opening balance saved successfully.',
            'data' => $this->formatOpeningBalance($opening),
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
            ->with('adjustments')
            ->where('user_id', $me->id)
            ->whereNull('beneficiary_id')
            ->orderByDesc('as_of_period')
            ->orderByDesc('id')
            ->first();

        if (!$opening) {
            return response()->json([
                'message' => 'OK',
                'data' => [
                    'set' => false,
                    'amount' => 0,
                    'original_amount' => 0,
                    'adjustments_total' => 0,
                    'effective_amount' => 0,
                    'as_of_period' => null,
                ],
            ]);
        }

        $data = $this->formatOpeningBalance($opening);

        return response()->json([
            'message' => 'OK',
            'data' => array_merge($data, [
                'set' => true,
            ]),
        ]);
    }
}