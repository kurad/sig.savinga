<?php

namespace App\Services;

use App\Models\User;
use App\Models\Penalty;
use App\Models\Transaction;
use App\Models\Contribution;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ContributionReportService
{
    /**
     * List contributions with filters.
     */
    public function list(array $filters, int $perPage = 15)
    {
        $query = Contribution::query()->with(['user:id,name,email,phone']);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['period'])) {
            $query->where('period_key', $filters['period']);
        } else {
            if (!empty($filters['from'])) $query->whereDate('expected_date', '>=', $filters['from']);
            if (!empty($filters['to']))   $query->whereDate('expected_date', '<=', $filters['to']);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('expected_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('expected_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('expected_date')->paginate($perPage);
    }

    /**
     * Member contribution summary (with auth).
     */
    public function memberSummary_old(User $viewer, User $member, ?string $from = null, ?string $to = null): array
    {
        // ✅ Enforce "self" unless admin/treasurer
        $allowedRoles = ['admin', 'treasurer'];
        $isSelf = (int) $viewer->id === (int) $member->id;

        if (!$isSelf && !in_array($viewer->role, $allowedRoles, true)) {
            throw new \Exception('Forbidden');
        }

        // Date window (defaults)
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth()->startOfDay();
        $toDate   = $to   ? Carbon::parse($to)->endOfDay()     : now()->endOfDay();

        // -----------------------------
        // 1) Totals from LEDGER (truth)
        // -----------------------------
        $totalContributed = (float) Transaction::where('user_id', $member->id)
            ->where('type', 'contribution')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->sum('credit');

        // Optional: penalties related to contributions (if you post them to ledger as type='penalty')
        // If you want ONLY penalties originating from contributions, you need to filter by source_type/source_id.
        // Here we compute penalties in period from penalties table by source_type='contribution'.
        $totalPenaltiesOnContributions = (float) \App\Models\Penalty::where('user_id', $member->id)
            ->where('source_type', 'contribution')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->sum('amount');

        // -----------------------------
        // 2) Envelope rows for TABLE
        // -----------------------------
        // Choose filter column: paid_date if you want "payments in window",
        // expected_date if you want "months due in window".
        // Your UI uses From/To as general period; using expected_date matches "compliance history".
        $contribQuery = Contribution::query()
            ->where('user_id', $member->id)
            ->whereBetween('expected_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderByDesc('expected_date');

        $contributions = $contribQuery->get();

        $counts = [
            'paid' => (int) $contributions->where('status', 'paid')->count(),
            'late' => (int) $contributions->where('status', 'late')->count(),
            'missed' => (int) $contributions->where('status', 'missed')->count(),
            'total_records' => (int) $contributions->count(),
        ];

        $avg = $counts['total_records'] > 0
            ? (float) ($contributions->sum('amount') / $counts['total_records'])
            : 0;

        // last contribution = latest envelope with paid_date not null (or latest expected)
        $last = Contribution::where('user_id', $member->id)
            ->orderByDesc('paid_date')
            ->orderByDesc('expected_date')
            ->first();

        return [
            'member' => $member->only(['id', 'name', 'email', 'phone']),
            'filters' => [
                'from' => $fromDate->toDateString(),
                'to'   => $toDate->toDateString(),
            ],
            'summary' => [
                // ✅ ledger-based (matches Statement)
                'total_contributed' => $totalContributed,
                'total_penalties_on_contributions' => $totalPenaltiesOnContributions,

                // envelope-based compliance stats
                'counts' => $counts,
                'average_contribution' => round($avg, 2),
                'last_contribution' => $last ? [
                    'id' => $last->id,
                    'period_key' => $last->period_key ?? null,
                    'amount' => (float) $last->amount,
                    'expected_date' => optional($last->expected_date)->toDateString(),
                    'paid_date' => $last->paid_date ? Carbon::parse($last->paid_date)->toDateString() : null,
                    'status' => $last->status,
                    'penalty_amount' => (float) ($last->penalty_amount ?? 0),
                ] : null,
            ],

            // ✅ FRONTEND expects this key
            'contributions' => $contributions->map(function ($c) {
                return [
                    'id' => $c->id,
                    'period_key' => $c->period_key ?? null,
                    'expected_date' => optional($c->expected_date)->toDateString(),
                    'paid_date' => $c->paid_date ? Carbon::parse($c->paid_date)->toDateString() : null,
                    'amount' => (float) $c->amount,
                    'status' => $c->status,
                    'penalty_amount' => (float) ($c->penalty_amount ?? 0),
                ];
            })->values(),
        ];
    }

    public function memberSummary($viewer, $member, ?string $from, ?string $to): array
    {
        // (keep your "Forbidden" checks here)

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate   = $to ? Carbon::parse($to)->endOfDay() : null;

        $base = Contribution::query()
            ->where('user_id', $member->id)
            ->orderByDesc('period_key');

        if ($fromDate && $toDate) {
            $base->where(function (Builder $q) use ($fromDate, $toDate) {
                // paid/late → paid_date
                $q->whereIn('status', ['paid', 'late'])
                    ->whereBetween('paid_date', [$fromDate, $toDate])

                    // missed → expected_date
                    ->orWhere(function (Builder $q2) use ($fromDate, $toDate) {
                        $q2->where('status', 'missed')
                            ->whereBetween('expected_date', [$fromDate, $toDate]);
                    });
            });
        } elseif ($fromDate) {
            $base->where(function (Builder $q) use ($fromDate) {
                $q->whereIn('status', ['paid', 'late'])->whereDate('paid_date', '>=', $fromDate)
                    ->orWhere(function (Builder $q2) use ($fromDate) {
                        $q2->where('status', 'missed')->whereDate('expected_date', '>=', $fromDate);
                    });
            });
        } elseif ($toDate) {
            $base->where(function (Builder $q) use ($toDate) {
                $q->whereIn('status', ['paid', 'late'])->whereDate('paid_date', '<=', $toDate)
                    ->orWhere(function (Builder $q2) use ($toDate) {
                        $q2->where('status', 'missed')->whereDate('expected_date', '<=', $toDate);
                    });
            });
        }

        $contributions = $base->get();

        // ✅ counts now reflect what’s in the table
        $counts = [
            'paid' => $contributions->where('status', 'paid')->count(),
            'late' => $contributions->where('status', 'late')->count(),
            'missed' => $contributions->where('status', 'missed')->count(),
            'total_records' => $contributions->count(),
        ];

        $totalContributed = (float) $contributions
            ->whereIn('status', ['paid', 'late'])
            ->sum('amount');

        $last = Contribution::where('user_id', $member->id)
            ->whereIn('status', ['paid', 'late'])
            ->orderByDesc('paid_date')
            ->first();

        return [
            'member' => $member->only(['id', 'name', 'email', 'phone']),
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'total_contributed' => $totalContributed,
                'total_penalties_on_contributions' => (float) $contributions->sum('penalty_amount'),
                'counts' => $counts,
                'average_contribution' => $counts['total_records'] > 0
                    ? round($totalContributed / max(1, ($counts['paid'] + $counts['late'])), 2)
                    : 0,
                'last_contribution' => $last,
            ],
            'contributions' => $contributions->values(),
        ];
    }
}
