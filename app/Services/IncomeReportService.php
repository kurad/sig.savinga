<?php

namespace App\Services;

use App\Models\Income;

class IncomeReportService
{
    public function list(array $filters, int $perPage = 15)
    {
        $q = Income::query();

        if (!empty($filters['from'])) {
            $q->whereDate('income_date', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->whereDate('income_date', '<=', $filters['to']);
        }
        if (!empty($filters['category'])) {
            $q->where('category', $filters['category']);
        }

        return $q->orderByDesc('income_date')->paginate($perPage);
    }
}
