<?php

namespace App\Services;

use App\Models\User;
use App\Models\Penalty;
use App\Models\Transaction;
use App\Models\Contribution;
use App\Models\Beneficiary;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ContributionReportService
{
    protected string $tz = 'Africa/Kigali';

    protected function applyOwnerFilter(Builder $query, ?int $userId, ?int $beneficiaryId): Builder
    {
        return $query
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId));
    }

    protected function contributionRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
        ];
    }

    /**
     * List contributions with filters.
     */
    public function list(array $filters, int $perPage = 15)
    {
        $ownerType = $filters['owner_type'] ?? null;
        $userId = !empty($filters['user_id']) ? (int) $filters['user_id'] : null;
        $beneficiaryId = !empty($filters['beneficiary_id']) ? (int) $filters['beneficiary_id'] : null;

        $query = Contribution::query()->with($this->contributionRelations());

        if ($ownerType === 'user') {
            $query->whereNotNull('user_id');
            if ($userId) {
                $query->where('user_id', $userId);
            }
        } elseif ($ownerType === 'beneficiary') {
            $query->whereNotNull('beneficiary_id');
            if ($beneficiaryId) {
                $query->where('beneficiary_id', $beneficiaryId);
            }
        } else {
            if ($userId) {
                $query->where('user_id', $userId);
            }
            if ($beneficiaryId) {
                $query->where('beneficiary_id', $beneficiaryId);
            }
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['period'])) {
            $query->where('period_key', $filters['period']);
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
     * Old user-only summary kept for compatibility.
     */
    public function memberSummary(User $viewer, User $member, ?string $from, ?string $to): array
    {
        return $this->ownerSummary(
            viewer: $viewer,
            userId: (int) $member->id,
            beneficiaryId: null,
            from: $from,
            to: $to
        );
    }

    /**
     * Owner-aware summary for either user or beneficiary.
     */
    public function ownerSummary($viewer, ?int $userId, ?int $beneficiaryId, ?string $from, ?string $to): array
    {
        $this->validateOwner($userId, $beneficiaryId);

        $allowedRoles = ['admin', 'treasurer'];

        if ($beneficiaryId) {
            $beneficiary = Beneficiary::with('guardian:id,name,email,phone')->findOrFail($beneficiaryId);

            $isSelf = (int) $viewer->id === (int) $beneficiary->guardian_user_id;
            if (!$isSelf && !in_array($viewer->role, $allowedRoles, true)) {
                throw new \Exception('Forbidden');
            }

            $ownerMeta = [
                'owner_type' => 'beneficiary',
                'beneficiary' => [
                    'id' => $beneficiary->id,
                    'name' => $beneficiary->name,
                    'relationship' => $beneficiary->relationship,
                ],
                'guardian' => $beneficiary->guardian
                    ? $beneficiary->guardian->only(['id', 'name', 'email', 'phone'])
                    : null,
            ];
        } else {
            $member = User::findOrFail($userId);

            $isSelf = (int) $viewer->id === (int) $member->id;
            if (!$isSelf && !in_array($viewer->role, $allowedRoles, true)) {
                throw new \Exception('Forbidden');
            }

            $ownerMeta = [
                'owner_type' => 'user',
                'member' => $member->only(['id', 'name', 'email', 'phone']),
            ];
        }

        $today = now($this->tz)->startOfDay();

        $fromDate = $from ? Carbon::parse($from, $this->tz)->startOfDay() : null;
        $toDate   = $to   ? Carbon::parse($to, $this->tz)->endOfDay() : null;

        $base = $this->applyOwnerFilter(Contribution::query(), $userId, $beneficiaryId)
            ->with($this->contributionRelations())
            ->orderByDesc('period_key');

        if ($fromDate && $toDate) {
            $base->where(function (Builder $q) use ($fromDate, $toDate) {
                $q->whereIn('status', ['paid', 'late'])
                    ->whereBetween('paid_date', [$fromDate, $toDate])
                    ->orWhere(function (Builder $q2) use ($fromDate, $toDate) {
                        $q2->where('status', 'missed')
                            ->whereBetween('expected_date', [$fromDate, $toDate]);
                    });
            });
        } elseif ($fromDate) {
            $base->where(function (Builder $q) use ($fromDate) {
                $q->whereIn('status', ['paid', 'late'])
                    ->whereDate('paid_date', '>=', $fromDate)
                    ->orWhere(function (Builder $q2) use ($fromDate) {
                        $q2->where('status', 'missed')
                            ->whereDate('expected_date', '>=', $fromDate);
                    });
            });
        } elseif ($toDate) {
            $base->where(function (Builder $q) use ($toDate) {
                $q->whereIn('status', ['paid', 'late'])
                    ->whereDate('paid_date', '<=', $toDate)
                    ->orWhere(function (Builder $q2) use ($toDate) {
                        $q2->where('status', 'missed')
                            ->whereDate('expected_date', '<=', $toDate);
                    });
            });
        }

        $contributions = $base->get();

        $contributions = $contributions->map(function ($c) use ($today) {
            if ($c->status === 'missed' && $c->expected_date) {
                $expected = Carbon::parse($c->expected_date, $this->tz)->startOfDay();
                if ($expected->gt($today)) {
                    $c->status = 'pending';
                    $c->penalty_amount = 0;
                }
            }
            return $c;
        });

        $counts = [
            'paid' => $contributions->where('status', 'paid')->count(),
            'late' => $contributions->where('status', 'late')->count(),
            'missed' => $contributions->where('status', 'missed')->count(),
            'pending' => $contributions->where('status', 'pending')->count(),
            'total_records' => $contributions->count(),
        ];

        $totalContributed = (float) $contributions
            ->whereIn('status', ['paid', 'late'])
            ->sum('amount');

        $paidLateCount = $counts['paid'] + $counts['late'];

        $last = $this->applyOwnerFilter(Contribution::query(), $userId, $beneficiaryId)
            ->whereIn('status', ['paid', 'late'])
            ->orderByDesc('paid_date')
            ->first();

        $totalPenaltiesOnContributions = (float) $this->applyOwnerFilter(Penalty::query(), $userId, $beneficiaryId)
            ->where('source_type', 'contribution')
            ->sum('amount');

        $ledgerTotalContributed = (float) $this->applyOwnerFilter(Transaction::query(), $userId, $beneficiaryId)
            ->where('type', 'contribution')
            ->sum('credit');

        return [
            ...$ownerMeta,
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => [
                'total_contributed' => $totalContributed,
                'ledger_total_contributed' => $ledgerTotalContributed,
                'total_penalties_on_contributions' => $totalPenaltiesOnContributions,
                'counts' => $counts,
                'average_contribution' => $paidLateCount > 0
                    ? round($totalContributed / $paidLateCount, 2)
                    : 0,
                'last_contribution' => $last,
            ],
            'contributions' => $contributions->values(),
        ];
    }

    protected function validateOwner(?int $userId, ?int $beneficiaryId): void
    {
        $hasUser = !is_null($userId);
        $hasBeneficiary = !is_null($beneficiaryId);

        if (($hasUser && $hasBeneficiary) || (!$hasUser && !$hasBeneficiary)) {
            throw new \InvalidArgumentException(
                'Summary must belong to either a user or a beneficiary.'
            );
        }
    }
}