<?php

namespace App\Services;

use App\Models\Income;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        ?string $description = null
    ): Income {
        return DB::transaction(function () use ($amount, $incomeDate, $recordedBy, $category, $description) {

            $income = Income::create([
                'amount' => $amount,
                'category' => $category,
                'description' => $description,
                'income_date' => Carbon::parse($incomeDate)->toDateString(),
                'recorded_by' => $recordedBy,
            ]);

            // Ledger: money IN to group
            $this->ledger->record(
                type: 'income',
                debit: 0,
                credit: $amount,
                userId: $recordedBy, // or system user
                reference: 'Income ID ' . $income->id . ($category ? " ({$category})" : ''),
                createdBy: $recordedBy,
                sourceType: 'income',
                sourceId: $income->id
            );

            return $income;
        });
    }
}
