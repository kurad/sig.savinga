<?php

namespace App\Services;

use App\Models\OpeningBalance;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    /**
     * Create opening balance for:
     * - member: user_id + beneficiary_id = null
     * - beneficiary: user_id + beneficiary_id
     */
    public function setOpeningBalance(array $data, int $adminId): OpeningBalance
    {
        return DB::transaction(function () use ($data, $adminId) {

            $userId        = (int) $data['user_id'];
            $beneficiaryId = isset($data['beneficiary_id']) && $data['beneficiary_id'] !== null
                ? (int) $data['beneficiary_id']
                : null;

            $asOf   = (string) $data['as_of_period'];
            $amount = (float) $data['amount'];
            $note   = $data['note'] ?? null;

            $existingQuery = OpeningBalance::query()
                ->where('user_id', $userId)
                ->where('as_of_period', $asOf);

            if ($beneficiaryId !== null) {
                $existingQuery->where('beneficiary_id', $beneficiaryId);
            } else {
                $existingQuery->whereNull('beneficiary_id');
            }

            $existing = $existingQuery->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    $beneficiaryId !== null ? 'beneficiary_id' : 'user_id' => [
                        $beneficiaryId !== null
                            ? 'Opening capital already set for this beneficiary in this period. Use an adjustment/correction flow if needed.'
                            : 'Opening capital already set for this member in this period. Use an adjustment/correction flow if needed.',
                    ],
                ]);
            }

            // Create opening balance row
            $ob = OpeningBalance::create([
                'user_id'        => $userId,
                'beneficiary_id' => $beneficiaryId,
                'as_of_period'   => $asOf,
                'amount'         => $amount,
                'note'           => $note,
                'created_by'     => $adminId,
            ]);

            // Create ledger entry
            $tx = Transaction::create([
                'user_id'     => $userId,
                'type'        => 'opening_balance',
                'debit'       => 0,
                'credit'      => $amount,
                'reference'   => $beneficiaryId !== null
                    ? "Beneficiary opening balance as of {$asOf}" . ($note ? " — {$note}" : "")
                    : "Opening balance as of {$asOf}" . ($note ? " — {$note}" : ""),
                'source_type' => 'opening_balance',
                'source_id'   => $ob->id,
                'created_by'  => $adminId,
            ]);

            $ob->update(['transaction_id' => $tx->id]);

            return $ob;
        });
    }
}