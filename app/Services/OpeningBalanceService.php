<?php

namespace App\Services;

use App\Models\OpeningBalance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    /**
     * Create opening balance once per user.
     * If you want edits later, handle them as "adjustments" (separate endpoint).
     */
    public function setOpeningBalance(array $data, int $adminId): OpeningBalance
    {
        return DB::transaction(function () use ($data, $adminId) {

            $userId = (int) $data['user_id'];
            $asOf   = (string) $data['as_of_period'];
            $amount = (float) $data['amount'];
            $note   = $data['note'] ?? null;

            $existing = OpeningBalance::where('user_id', $userId)->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'user_id' => ['Opening capital already set for this member. Use an adjustment/correction flow if needed.'],
                ]);
            }

            // Create the opening balance row (without transaction_id first)
            $ob = OpeningBalance::create([
                'user_id'      => $userId,
                'as_of_period' => $asOf,
                'amount'       => $amount,
                'note'         => $note,
                'created_by'   => $adminId,
            ]);

            // Create transaction ledger entry (credit)
            // transactions has credit/debit, reference, source_type/source_id, created_by :contentReference[oaicite:3]{index=3}
            $tx = Transaction::create([
                'user_id'     => $userId,
                'type'        => 'opening_balance',
                'debit'       => 0,
                'credit'      => $amount,
                'reference'   => "Opening balance as of {$asOf}" . ($note ? " — {$note}" : ""),
                'source_type' => 'opening_balance',
                'source_id'   => $ob->id,
                'created_by'  => $adminId,
            ]);

            $ob->transaction_id = $tx->id;
            $ob->save();

            return $ob;
        });
    }
}
