<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContributionAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'contribution_batch_id',
        'contribution_id',
        'transaction_id',
        'period_key',
        'allocated_amount',
        'before_amount',
        'after_amount',
        'before_paid_date',
        'after_paid_date',
        'before_status',
        'after_status',
        'before_penalty_amount',
        'after_penalty_amount',
        'before_expected_date',
        'after_expected_date',
        'before_recorded_by',
        'after_recorded_by',
        'created_new',
        'penalty_applied_now',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'before_amount' => 'decimal:2',
        'after_amount' => 'decimal:2',
        'before_penalty_amount' => 'decimal:2',
        'after_penalty_amount' => 'decimal:2',
        'before_paid_date' => 'date',
        'after_paid_date' => 'date',
        'before_expected_date' => 'date',
        'after_expected_date' => 'date',
        'created_new' => 'boolean',
        'penalty_applied_now' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ContributionBatch::class, 'contribution_batch_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function scopeForPeriod(Builder $query, string $periodKey): Builder
    {
        return $query->where('period_key', $periodKey);
    }

    public function scopeCreatedNew(Builder $query): Builder
    {
        return $query->where('created_new', true);
    }

    public function scopePenaltyApplied(Builder $query): Builder
    {
        return $query->where('penalty_applied_now', true);
    }

    public function didCreateNewContribution(): bool
    {
        return (bool) $this->created_new;
    }

    public function didApplyPenalty(): bool
    {
        return (bool) $this->penalty_applied_now;
    }
}