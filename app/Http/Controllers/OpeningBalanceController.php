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

    public function showByUser(Request $request, int $userId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $q = OpeningBalance::query()
            ->where('user_id', $userId)
            ->whereNull('beneficiary_id');

        if ($request->filled('as_of_period')) {
            $q->where('as_of_period', $request->as_of_period);
        } else {
            $q->orderByDesc('as_of_period')->orderByDesc('id');
        }

        $row = $q->first();

        return response()->json([
            'data' => $row,
        ]);
    }

    public function showByBeneficiary(Request $request, int $beneficiaryId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);

        $beneficiary = Beneficiary::findOrFail($beneficiaryId);

        $q = OpeningBalance::query()
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

        return response()->json([
            'data' => $item,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'as_of_period'   => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'note'           => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($validated['user_id']) && empty($validated['beneficiary_id'])) {
            return response()->json([
                'message' => 'Either user_id or beneficiary_id is required.',
            ], 422);
        }

        $validated['user_id'] = $this->resolveOwnerUserId(
            $validated['user_id'] ?? null,
            $validated['beneficiary_id'] ?? null
        );

        $adminId = (int) auth()->id();

        $row = $this->service->setOpeningBalance($validated, $adminId);

        return response()->json([
            'message' => 'Opening capital saved.',
            'data'    => $row,
        ]);
    }

    public function update(Request $request, OpeningBalance $openingBalance)
    {
        $data = $request->validate([
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'integer', 'exists:beneficiaries,id'],
            'as_of_period'   => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'note'           => ['nullable', 'string', 'max:1000'],
        ]);

        if (empty($data['user_id']) && empty($data['beneficiary_id'])) {
            return response()->json([
                'message' => 'Either user_id or beneficiary_id is required.',
            ], 422);
        }

        $resolvedUserId = $this->resolveOwnerUserId(
            $data['user_id'] ?? null,
            $data['beneficiary_id'] ?? null
        );

        $openingBalance->update([
            'user_id'        => $resolvedUserId,
            'beneficiary_id' => $data['beneficiary_id'] ?? null,
            'as_of_period'   => $data['as_of_period'],
            'amount'         => $data['amount'],
            'note'           => $data['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'Opening balance updated successfully.',
            'data' => $openingBalance->fresh(),
        ]);
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