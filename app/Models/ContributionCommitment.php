<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ContributionCommitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'amount',
        'cycle_start_period',
        'cycle_end_period',
        'cycle_months',
        'status',
        'created_by',
        'activated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cycle_months' => 'integer',
        'activated_at' => 'datetime',
    ];

    /* =========================
       Relationships
       ========================= */

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* =========================
       Scopes
       ========================= */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForBeneficiary(Builder $query, int $beneficiaryId): Builder
    {
        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeUserLevel(Builder $query): Builder
    {
        return $query->whereNull('beneficiary_id');
    }

    public function scopeBeneficiaryLevel(Builder $query): Builder
    {
        return $query->whereNotNull('beneficiary_id');
    }

    public function scopeForOwner(Builder $query, int $userId, ?int $beneficiaryId = null): Builder
    {
        $query->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            return $query->whereNull('beneficiary_id');
        }

        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeCoversPeriod(Builder $query, string $periodKey): Builder
    {
        return $query
            ->where('cycle_start_period', '<=', $periodKey)
            ->where('cycle_end_period', '>=', $periodKey);
    }

    /* =========================
       Convenience
       ========================= */

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isUserLevel(): bool
    {
        return is_null($this->beneficiary_id);
    }

    public function isBeneficiaryLevel(): bool
    {
        return !is_null($this->beneficiary_id);
    }

    public function covers(string $periodKey): bool
    {
        return $this->cycle_start_period <= $periodKey
            && $this->cycle_end_period >= $periodKey;
    }
}