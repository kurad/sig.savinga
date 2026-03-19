<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $amount = (float) $amount;
        if($amount <= 0){
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than 0.']]);
        }
        $expenseDt = Carbon::parse($expenseDate)->startOfDay();
        return DB::transaction(function () use ($amount, $expenseDt, $recordedBy, $category, $description) {

         // ✅ Lock ledger rows so balance can't change mid-check (prevents race conditions)
        $credits = (float) Transaction::query()->lockForUpdate()->sum('credit');
        $debits  = (float) Transaction::query()->lockForUpdate()->sum('debit');
        $available = $credits - $debits;

        if ($amount > $available) {
            throw ValidationException::withMessages([
                'amount' => ["Insufficient funds. Available: " . number_format($available, 0) . " RWF."],
            ]);
        }
            $expense = Expense::create([
                'amount' => $amount,
                'category' => $category,
                'description' => $description,
                'expense_date' => $expenseDt->toDateString(),
                'recorded_by' => $recordedBy,
            ]);

            // Ledger: money OUT from group
            $this->ledger->record(
                type: 'expense',
                debit: $amount,
                credit: 0,
                userId: $recordedBy, // optional: or null if your transactions allow
                reference: 'Expense # ' . $expense->id . ($category ? " ({$category})" : ''),
                createdBy: $recordedBy,
                sourceType: 'expense',
                sourceId: $expense->id
            );

            return $expense;
        });
    }
}
