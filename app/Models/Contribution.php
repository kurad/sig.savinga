<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'financial_year_rule_id',
        'period_key',
        'amount',
        'expected_date',
        'paid_date',
        'status',
        'penalty_amount',
        'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
        'expected_date' => 'date',
        'paid_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function financialYearRule(): BelongsTo
    {
        return $this->belongsTo(FinancialYearRule::class, 'financial_year_rule_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ContributionAllocation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeForOwner(Builder $query, int $userId, ?int $beneficiaryId = null): Builder
    {
        $query->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            return $query->whereNull('beneficiary_id');
        }

        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeForPeriod(Builder $query, string $periodKey): Builder
    {
        return $query->where('period_key', $periodKey);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    public function scopeLate(Builder $query): Builder
    {
        return $query->where('status', 'late');
    }

    public function scopeMissed(Builder $query): Builder
    {
        return $query->where('status', 'missed');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isLate(): bool
    {
        return $this->status === 'late';
    }

    public function isMissed(): bool
    {
        return $this->status === 'missed';
    }

    public function isUserLevel(): bool
    {
        return is_null($this->beneficiary_id);
    }

    public function isBeneficiaryLevel(): bool
    {
        return !is_null($this->beneficiary_id);
    }
    public function adjustments()
    {
        return $this->morphMany(\App\Models\Adjustment::class, 'adjustable');
    }

    public function effectiveAmount(): float
    {
        return round((float) $this->amount + (float) $this->adjustments()->sum('amount'), 2);
    }
}
