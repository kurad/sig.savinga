<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StatementReportService
{
    /**
     * Admin: Group ledger list
     * Filters: user_id, type, from, to, q
     */
    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $q = Transaction::query()
            ->with(['user:id,name,email,phone'])
            ->orderByDesc('created_at');

        if (!empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }

        if (!empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $q->whereDate('created_at', '<=', $filters['to']);
        }

        // Search in reference (and optionally type)
        if (!empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $q->where(function ($sub) use ($term) {
                $sub->where('reference', 'like', "%{$term}%")
                    ->orWhere('type', 'like', "%{$term}%");
            });
        }

        return $q->paginate($perPage);
    }
}
