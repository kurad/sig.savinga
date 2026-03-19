<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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

    public function batch()
    {
        return $this->belongsTo(ContributionBatch::class, 'contribution_batch_id');
    }

    public function contribution()
    {
        return $this->belongsTo(Contribution::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}