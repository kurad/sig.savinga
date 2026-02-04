<?php

namespace App\Services;

use App\Models\User;
use App\Models\Penalty;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;

class PenaltyReportService
{
    /**
     * List penalties with filters.
     * Filters: user_id, status, source_type, from, to
     */
    public function list(array $filters, int $perPage = 15)
    {
        $query = Penalty::query()->with(['user:id,name,email,phone']);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']); // unpaid|paid|waived
        }

        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']); // contribution|loan|manual
        }

        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
    public function memberPenalties(User $viewer, User $member, array $filters, int $perPage = 15)
    {
        $this->authorizeViewer($viewer, $member);

        $q = Penalty::query()
            ->where('user_id', $member->id)
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        if (!empty($filters['source_type'])) {
            $q->where('source_type', $filters['source_type']);
        }

        if (!empty($filters['from'])) {
            $q->whereDate('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $q->whereDate('created_at', '<=', $filters['to']);
        }

        return $q->paginate($perPage);
    }

    /**
     * Member penalties summary (with authorization).
     * Returns totals by status + counts.
     */
    public function memberSummary(User $viewer, User $member, ?string $from = null, ?string $to = null): array
    {
        $this->authorizeViewer($viewer, $member);

        $q = Penalty::query()->where('user_id', $member->id);

        if ($from) $q->whereDate('created_at', '>=', $from);
        if ($to)   $q->whereDate('created_at', '<=', $to);

        $totals = (clone $q)->selectRaw('
            COALESCE(SUM(amount),0) as total_amount,
            COALESCE(SUM(CASE WHEN status = "unpaid" THEN amount ELSE 0 END),0) as unpaid_amount,
            COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END),0) as paid_amount,
            COALESCE(SUM(CASE WHEN status = "waived" THEN amount ELSE 0 END),0) as waived_amount,

            COALESCE(SUM(CASE WHEN status = "unpaid" THEN 1 ELSE 0 END),0) as unpaid_count,
            COALESCE(SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END),0) as paid_count,
            COALESCE(SUM(CASE WHEN status = "waived" THEN 1 ELSE 0 END),0) as waived_count,
            COUNT(*) as total_records
        ')->first();

        $bySource = (clone $q)->selectRaw('
            source_type,
            COALESCE(SUM(amount),0) as total_amount,
            COUNT(*) as total_records
        ')
            ->groupBy('source_type')
            ->orderByDesc('total_amount')
            ->get();

        $latest = (clone $q)
            ->orderByDesc('created_at')
            ->first(['id', 'source_type', 'source_id', 'amount', 'reason', 'status', 'created_at', 'paid_at']);

        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
            ],
            'filters' => [
                'from' => $from,
                'to' => $to,
            ],
            'summary' => [
                'amounts' => [
                    'total' => (float) ($totals->total_amount ?? 0),
                    'unpaid' => (float) ($totals->unpaid_amount ?? 0),
                    'paid' => (float) ($totals->paid_amount ?? 0),
                    'waived' => (float) ($totals->waived_amount ?? 0),
                ],
                'counts' => [
                    'unpaid' => (int) ($totals->unpaid_count ?? 0),
                    'paid' => (int) ($totals->paid_count ?? 0),
                    'waived' => (int) ($totals->waived_count ?? 0),
                    'total_records' => (int) ($totals->total_records ?? 0),
                ],
                'by_source_type' => $bySource->map(fn($row) => [
                    'source_type' => $row->source_type,
                    'total_amount' => (float) $row->total_amount,
                    'total_records' => (int) $row->total_records,
                ])->values(),
                'latest_penalty' => $latest ? [
                    'id' => $latest->id,
                    'source_type' => $latest->source_type,
                    'source_id' => $latest->source_id,
                    'amount' => (float) $latest->amount,
                    'reason' => $latest->reason,
                    'status' => $latest->status,
                    'created_at' => optional($latest->created_at)->toDateTimeString(),
                    'paid_at' => $latest->paid_at ? $latest->paid_at->toDateTimeString() : null,
                ] : null,
            ],
        ];
    }
    

    protected function authorizeViewer(User $viewer, User $member): void
    {
        $isPrivileged = in_array($viewer->role, ['admin', 'treasurer'], true);
        $isSelf = (int) $viewer->id === (int) $member->id;

        if (!$isPrivileged && !$isSelf) {
            throw new AuthorizationException('Forbidden');
        }
    }
}
