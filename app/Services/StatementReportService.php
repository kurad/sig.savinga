<?php

namespace App\Services;

use App\Models\Transaction;

class StatementReportService
{
    public function list(array $filters, int $perPage = 15): array
    {
        $q = Transaction::query()
            ->with(['user:id,name,email,phone']);

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

        if (!empty($filters['q'])) {
            $term = trim((string) $filters['q']);

            $q->where(function ($sub) use ($term) {
                $sub->where('reference', 'like', "%{$term}%")
                    ->orWhere('type', 'like', "%{$term}%")
                    ->orWhere('source_type', 'like', "%{$term}%");
            });
        }

        $totals = (clone $q)
            ->selectRaw("
                COALESCE(SUM(credit), 0) as credit,
                COALESCE(SUM(debit), 0) as debit,
                COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net
            ")
            ->first();

        $data = $q
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return [
            'data' => $data,
            'totals' => [
                'credit' => round((float) $totals->credit, 2),
                'debit' => round((float) $totals->debit, 2),
                'net' => round((float) $totals->net, 2),
            ],
        ];
    }
}