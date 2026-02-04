<?php

namespace App\Http\Controllers;

use App\Models\OpeningBalance;
use App\Services\OpeningBalanceService;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $service) {}

    public function showByUser(int $userId)
    {
        $row = OpeningBalance::where('user_id', $userId)->first();
        return response()->json(['data' => $row]);
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
