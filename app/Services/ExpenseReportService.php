<?php

namespace App\Services;

use App\Models\Expense;

class ExpenseReportService
{
    public function list(array $filters, int $perPage = 15)
    {
        $q = Expense::query();

        if (!empty($filters['from'])) {
            $q->whereDate('expense_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate('expense_date', '<=', $filters['to']);
        }
        if (!empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }

        return $q->orderByDesc('expense_date')->paginate($perPage);
    }
}
