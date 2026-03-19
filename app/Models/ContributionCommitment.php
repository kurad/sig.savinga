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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* =========================
       Scopes (optional helpers)
       ========================= */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeCoversPeriod(Builder $query, string $periodKey): Builder
    {
        // periodKey = 'YYYY-MM'
        return $query
            ->where('cycle_start_period', '<=', $periodKey)
            ->where('cycle_end_period', '>=', $periodKey);
    }

    /* =========================
       Convenience
       ========================= */

    public function isActive(): bool
    {
        return ($this->status ?? 'active') === 'active';
    }
    public function covers(string $periodKey): bool
    {
        return $this->cycle_start_period <= $periodKey && $this->cycle_end_period >= $periodKey;
    }
}
