<?php

namespace App\Http\Controllers;

use App\Models\OpeningBalance;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $service) {}

    public function showByUser(Request $request, int $userId)
    {
        $request->validate([
            'as_of_period' => ['nullable', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
        ]);
        $q = OpeningBalance::where('user_id', $userId);

        if ($request->filled('as_of_period')) {
            $q->where('as_of_period', $request->as_of_period);
        } else {
            $q->latest('as_of_period');
        }

        $row = $q->first();
        return response()->json(['data' => $row]);
    }
    public function showByBeneficiary($beneficiaryId)
    {
        $item = OpeningBalance::query()
            ->where('beneficiary_id', $beneficiaryId)
            ->orderByDesc('as_of_period')
            ->orderByDesc('id')
            ->first();

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
            'user_id'      => ['required', 'integer', 'exists:users,id'],
            'as_of_period' => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'], // YYYY-MM
            'amount'       => ['required', 'numeric', 'min:0'],
            'note'         => ['nullable', 'string', 'max:255'],
        ]);

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
            'user_id' => ['nullable', 'exists:users,id'],
            'beneficiary_id' => ['nullable', 'exists:beneficiaries,id'],
            'as_of_period' => ['required', 'regex:/^\d{4}\-(0[1-9]|1[0-2])$/'],
            'amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // exactly one owner must be set
        if (empty($data['user_id']) && empty($data['beneficiary_id'])) {
            return response()->json([
                'message' => 'Either user_id or beneficiary_id is required.',
            ], 422);
        }

        if (!empty($data['user_id']) && !empty($data['beneficiary_id'])) {
            return response()->json([
                'message' => 'Only one of user_id or beneficiary_id may be provided.',
            ], 422);
        }

        $openingBalance->update([
            'user_id' => $data['user_id'] ?? null,
            'beneficiary_id' => $data['beneficiary_id'] ?? null,
            'as_of_period' => $data['as_of_period'],
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'message' => 'Opening balance updated successfully.',
            'data' => $openingBalance->fresh(),
        ]);
    }
    public function myOpeningBalance(Request $request)
    {
        $me = $request->user();

        $opening = \App\Models\OpeningBalance::query()
            ->where('user_id', $me->id)
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
