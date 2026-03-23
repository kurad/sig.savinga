<?php

namespace App\Services;

use App\Models\Income;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IncomeService
{
    public function __construct(
        protected TransactionService $ledger
    ) {}

    public function record(
        float $amount,
        string $incomeDate,
        int $recordedBy,
        ?string $category = null,
        ?string $description = null,
        ?int $userId = null,
        ?int $beneficiaryId = null
    ): Income {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Income amount must be greater than zero.');
        }

        if ($userId && $beneficiaryId) {
            throw new InvalidArgumentException('Income cannot belong to both member and beneficiary at the same time.');
        }

        return DB::transaction(function () use (
            $amount,
            $incomeDate,
            $recordedBy,
            $category,
            $description,
            $userId,
            $beneficiaryId
        ) {
            $income = Income::create([
                'amount' => round($amount, 2),
                'category' => $category,
                'description' => $description,
                'income_date' => Carbon::parse($incomeDate)->toDateString(),
                'recorded_by' => $recordedBy,
                'user_id' => $userId,
                'beneficiary_id' => $beneficiaryId,
            ]);

            $ownerLabel = $userId
                ? " | Member ID {$userId}"
                : ($beneficiaryId ? " | Beneficiary ID {$beneficiaryId}" : '');

            $this->ledger->record(
                type: 'income',
                debit: 0,
                credit: round($amount, 2),
                userId: $recordedBy,
                reference: 'Income ID ' . $income->id . ($category ? " ({$category})" : '') . $ownerLabel,
                createdBy: $recordedBy,
                sourceType: 'income',
                sourceId: $income->id
            );

            return $income;
        });
    }
}