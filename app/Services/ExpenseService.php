<?php

namespace App\Services;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(
        protected TransactionService $ledger
    ) {}

    public function record(
        float $amount,
        string $expenseDate,
        int $recordedBy,
        ?string $category = null,
        ?string $description = null
    ): Expense {
        return DB::transaction(function () use ($amount, $expenseDate, $recordedBy, $category, $description) {

            $expense = Expense::create([
                'amount' => $amount,
                'category' => $category,
                'description' => $description,
                'expense_date' => Carbon::parse($expenseDate)->toDateString(),
                'recorded_by' => $recordedBy,
            ]);

            // Ledger: money OUT from group
            $this->ledger->record(
                type: 'expense',
                debit: $amount,
                credit: 0,
                userId: $recordedBy, // optional: or null if your transactions allow
                reference: 'Expense ID ' . $expense->id . ($category ? " ({$category})" : ''),
                createdBy: $recordedBy,
                sourceType: 'expense',
                sourceId: $expense->id
            );

            return $expense;
        });
    }
}
