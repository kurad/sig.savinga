<?php

namespace App\Services;

use App\Models\User;
use App\Models\Penalty;
use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;

class PenaltyReportService
{
    protected function penaltyRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'beneficiary:id,guardian_user_id,name,relationship',
            'beneficiary.guardian:id,name,email,phone',
        ];
    }

    protected function applyOwnerFilter(Builder $query, ?int $userId, ?int $beneficiaryId): Builder
    {
        return $query
            ->when(!is_null($userId), fn ($q) => $q->where('user_id', $userId))
            ->when(!is_null($beneficiaryId), fn ($q) => $q->where('beneficiary_id', $beneficiaryId));
    }

    protected function validateOwner(?int $userId, ?int $beneficiaryId): void
    {
        $hasUser = !is_null($userId);
        $hasBeneficiary = !is_null($beneficiaryId);

        if (($hasUser && $hasBeneficiary) || (!$hasUser && !$hasBeneficiary)) {
            throw new \InvalidArgumentException(
                'Penalty report must belong to either a user or a beneficiary.'
            );
        }
    }

    /**
     * List penalties with filters.
     * Filters: owner_type, user_id, beneficiary_id, status, source_type, from, to
     */
    public function list(array $filters, int $perPage = 15)
    {
        $ownerType = $filters['owner_type'] ?? null;
        $userId = !empty($filters['user_id']) ? (int) $filters['user_id'] : null;
        $beneficiaryId = !empty($filters['beneficiary_id']) ? (int) $filters['beneficiary_id'] : null;

        $query = Penalty::query()->with($this->penaltyRelations());

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

        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * User-based penalties list kept for compatibility.
     */
    public function memberPenalties(User $viewer, User $member, array $filters, int $perPage = 15)
    {
        $this->authorizeViewer($viewer, $member);

        $q = Penalty::query()
            ->with($this->penaltyRelations())
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
     * User-based summary kept for compatibility.
     */
    public function memberSummary(User $viewer, User $member, ?string $from = null, ?string $to = null): array
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
     * Generic owner summary for user or beneficiary.
     */
    public function ownerSummary($viewer, ?int $userId, ?int $beneficiaryId, ?string $from = null, ?string $to = null): array
    {
        $this->validateOwner($userId, $beneficiaryId);

        if (!is_null($beneficiaryId)) {
            $beneficiary = Beneficiary::with('guardian:id,name,email,phone')->findOrFail($beneficiaryId);
            $this->authorizeBeneficiaryViewer($viewer, $beneficiary);

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
            $this->authorizeViewer($viewer, $member);

            $ownerMeta = [
                'owner_type' => 'user',
                'member' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'phone' => $member->phone,
                ],
            ];
        }

        $q = $this->applyOwnerFilter(Penalty::query(), $userId, $beneficiaryId);

        if ($from) {
            $q->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $q->whereDate('created_at', '<=', $to);
        }

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
            ->with($this->penaltyRelations())
            ->orderByDesc('created_at')
            ->first(['id', 'user_id', 'beneficiary_id', 'source_type', 'source_id', 'amount', 'reason', 'status', 'created_at', 'paid_at']);

        return [
            ...$ownerMeta,
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
                'by_source_type' => $bySource->map(fn ($row) => [
                    'source_type' => $row->source_type,
                    'total_amount' => (float) $row->total_amount,
                    'total_records' => (int) $row->total_records,
                ])->values(),
                'latest_penalty' => $latest ? [
                    'id' => $latest->id,
                    'user_id' => $latest->user_id,
                    'beneficiary_id' => $latest->beneficiary_id,
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

    protected function authorizeBeneficiaryViewer(User $viewer, Beneficiary $beneficiary): void
    {
        $isPrivileged = in_array($viewer->role, ['admin', 'treasurer'], true);
        $isGuardian = (int) $viewer->id === (int) $beneficiary->guardian_user_id;

        if (!$isPrivileged && !$isGuardian) {
            throw new AuthorizationException('Forbidden');
        }
    }
}